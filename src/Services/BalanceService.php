<?php

namespace Alimrun\Ledger\Services;

use Alimrun\Ledger\Enums\NormalSide;
use Alimrun\Ledger\Exceptions\LedgerException;
use Alimrun\Ledger\Models\Account;
use Alimrun\Ledger\Models\Balance;
use Alimrun\Ledger\Models\Entry;
use Alimrun\Ledger\Support\Amount;
use Alimrun\Ledger\Support\Ref;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository;

/**
 * Running totals per account and party, and balance queries.
 */
class BalanceService
{
    public function __construct(
        protected Repository $config,
        protected AccountService $accounts,
    ) {}

    public function tracksAll(): bool
    {
        return $this->config->get('ledger.balances.track', 'party') === 'all';
    }

    public function tracks(?Ref $party): bool
    {
        return $party !== null || $this->tracksAll();
    }

    /**
     * Adds freshly written entries to their balance rows. Rows are locked in
     * a fixed order so two postings can never wait on each other in a cycle.
     *
     * @param  iterable<Entry>  $entries
     */
    public function apply(iterable $entries): void
    {
        $deltas = [];

        foreach ($entries as $entry) {
            if ($entry->party_type === null && ! $this->tracksAll()) {
                continue;
            }

            $key = sprintf('%020d|%s|%s', $entry->account_id, $entry->party_type ?? '', $entry->party_id ?? '');

            $deltas[$key] ??= [
                'account_id' => $entry->account_id,
                'party_type' => $entry->party_type ?? '',
                'party_id' => $entry->party_id ?? '',
                'debit' => Amount::zero(),
                'credit' => Amount::zero(),
                'last_entry_id' => 0,
            ];

            $deltas[$key]['debit'] = $deltas[$key]['debit']->plus(Amount::of($entry->debit));
            $deltas[$key]['credit'] = $deltas[$key]['credit']->plus(Amount::of($entry->credit));
            $deltas[$key]['last_entry_id'] = max($deltas[$key]['last_entry_id'], $entry->id);
        }

        ksort($deltas, SORT_STRING);

        foreach ($deltas as $delta) {
            $row = $this->lockRow($delta['account_id'], $delta['party_type'], $delta['party_id']);

            Balance::query()->toBase()->where('id', $row->id)->update([
                'debit_total' => (string) Amount::of($row->debit_total)->plus($delta['debit']),
                'credit_total' => (string) Amount::of($row->credit_total)->plus($delta['credit']),
                'last_entry_id' => max((int) $row->last_entry_id, $delta['last_entry_id']),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Locks and returns the balance row, creating it when missing. Call it
     * inside a transaction.
     */
    public function lockRow(int $accountId, string $partyType, string $partyId): Balance
    {
        $query = Balance::query()
            ->where('account_id', $accountId)
            ->where('party_type', $partyType)
            ->where('party_id', $partyId);

        $row = (clone $query)->lockForUpdate()->first();

        if ($row === null) {
            Balance::query()->insertOrIgnore([
                'account_id' => $accountId,
                'party_type' => $partyType,
                'party_id' => $partyId,
                'debit_total' => '0',
                'credit_total' => '0',
                'updated_at' => now(),
            ]);

            $row = $query->lockForUpdate()->firstOrFail();
        }

        return $row;
    }

    /**
     * Locked balance for a payment or limit check. Hold the returned value
     * only inside the same transaction that posts against it.
     */
    public function locked(Account $account, ?Ref $party, ?NormalSide $side = null): BigDecimal
    {
        if (! $this->tracks($party)) {
            throw new LedgerException("Balances are not tracked for account [{$account->code}] without a party. Set ledger.balances.track to \"all\".");
        }

        $row = $this->lockRow($account->id, $party?->type ?? '', $party?->id ?? '');

        return $row->balance($side ?? $account->normal_side);
    }

    /**
     * @param  list<Account>  $accounts
     */
    public function balance(
        array $accounts,
        ?Ref $party = null,
        ?NormalSide $side = null,
        ?CarbonInterface $asOf = null,
        ?string $segment = null,
    ): BigDecimal {
        $side ??= $accounts[0]->normal_side;
        $ids = array_values(array_unique(array_merge(...array_map(fn (Account $a) => $this->accounts->descendantIds($a), $accounts))));

        if ($asOf === null && $segment === null && $this->tracks($party)) {
            $totals = Balance::query()->toBase()
                ->whereIn('account_id', $ids)
                ->when($party, fn ($q) => $q->where('party_type', $party->type)->where('party_id', $party->id))
                ->selectRaw('SUM(debit_total) as debit, SUM(credit_total) as credit')
                ->first();
        } else {
            $totals = $this->entryTotals($ids, $party, $asOf, $segment)->first();
        }

        return $side->balance(Amount::of($totals->debit ?? 0), Amount::of($totals->credit ?? 0));
    }

    /**
     * @param  list<int>  $accountIds
     */
    public function entryTotals(array $accountIds, ?Ref $party = null, ?CarbonInterface $asOf = null, ?string $segment = null, ?CarbonInterface $from = null)
    {
        return Entry::query()->toBase()
            ->whereIn('account_id', $accountIds)
            ->when($party, fn ($q) => $q->where('party_type', $party->type)->where('party_id', $party->id))
            ->when($from, fn ($q) => $q->where('occurred_at', '>=', $from))
            ->when($asOf, fn ($q) => $q->where('occurred_at', '<=', $asOf))
            ->when($segment !== null, fn ($q) => $q->where('segment', $segment))
            ->selectRaw('SUM(debit) as debit, SUM(credit) as credit');
    }

    /**
     * Recomputes every balance row from the entries.
     *
     * @return int rows written
     */
    public function rebuild(): int
    {
        $written = 0;

        Balance::query()->toBase()->delete();

        Entry::query()->toBase()
            ->when(! $this->tracksAll(), fn ($q) => $q->whereNotNull('party_type'))
            ->selectRaw('account_id, party_type, party_id, SUM(debit) as debit, SUM(credit) as credit, MAX(id) as last_entry_id')
            ->groupBy('account_id', 'party_type', 'party_id')
            ->orderBy('account_id')
            ->get()
            ->chunk(500)
            ->each(function ($chunk) use (&$written): void {
                Balance::query()->insert($chunk->map(fn ($row) => [
                    'account_id' => $row->account_id,
                    'party_type' => $row->party_type ?? '',
                    'party_id' => $row->party_id ?? '',
                    'debit_total' => (string) Amount::of($row->debit),
                    'credit_total' => (string) Amount::of($row->credit),
                    'last_entry_id' => $row->last_entry_id,
                    'updated_at' => now(),
                ])->all());

                $written += $chunk->count();
            });

        return $written;
    }
}
