<?php

namespace Alimrun\Ledger\Reports;

use Alimrun\Ledger\Models\Account;
use Alimrun\Ledger\Models\Balance;
use Alimrun\Ledger\Models\Entry;
use Alimrun\Ledger\Services\AccountService;
use Alimrun\Ledger\Support\Amount;
use Carbon\CarbonInterface;

/**
 * Sub-ledger listing: every party's balance on a control account, such as
 * all vendor payables or all customer wallets.
 */
final class PartyBalances extends Report
{
    public function __construct(
        public readonly string $account,
        public readonly ?CarbonInterface $asOf,
        public readonly array $rows,
        public readonly string $total,
    ) {}

    public static function generate(AccountService $accounts, Account $account, ?CarbonInterface $asOf = null, bool $includeZero = false): self
    {
        $ids = $accounts->descendantIds($account);

        $source = $asOf === null
            ? Balance::query()->toBase()
                ->whereIn('account_id', $ids)
                ->where('party_type', '!=', '')
                ->selectRaw('party_type, party_id, SUM(debit_total) as debit, SUM(credit_total) as credit')
            : Entry::query()->toBase()
                ->whereIn('account_id', $ids)
                ->whereNotNull('party_type')
                ->where('occurred_at', '<=', $asOf)
                ->selectRaw('party_type, party_id, SUM(debit) as debit, SUM(credit) as credit');

        $rows = [];
        $total = Amount::zero();

        foreach ($source->groupBy('party_type', 'party_id')->get() as $row) {
            $balance = $account->normal_side->balance(Amount::of($row->debit), Amount::of($row->credit));

            if (! $includeZero && $balance->isZero()) {
                continue;
            }

            $total = $total->plus($balance);
            $rows[] = [
                'party_type' => $row->party_type,
                'party_id' => $row->party_id,
                'debit' => (string) Amount::of($row->debit),
                'credit' => (string) Amount::of($row->credit),
                'balance' => (string) $balance,
            ];
        }

        usort($rows, fn ($a, $b) => Amount::of($b['balance'])->compareTo(Amount::of($a['balance'])));

        return new self($account->code, $asOf, $rows, (string) $total);
    }

    public function toArray(): array
    {
        return [
            'account' => $this->account,
            'as_of' => $this->asOf?->toIso8601String(),
            'rows' => $this->rows,
            'total' => $this->total,
        ];
    }
}
