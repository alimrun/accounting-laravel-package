<?php

namespace Alimrun\Ledger\Reports;

use Alimrun\Ledger\Enums\NormalSide;
use Alimrun\Ledger\Models\Account;
use Alimrun\Ledger\Models\Entry;
use Alimrun\Ledger\Services\AccountService;
use Alimrun\Ledger\Services\BalanceService;
use Alimrun\Ledger\Support\Amount;
use Alimrun\Ledger\Support\Ref;
use Carbon\CarbonInterface;

/**
 * General ledger for one or more accounts, optionally for one party:
 * opening balance, each line with a running balance, closing balance.
 * Use it for vendor, customer, employee and delivery-man statements.
 */
final class AccountStatement extends Report
{
    public function __construct(
        public readonly array $accounts,
        public readonly ?Ref $party,
        public readonly NormalSide $side,
        public readonly ?CarbonInterface $from,
        public readonly ?CarbonInterface $to,
        public readonly string $opening,
        public readonly array $lines,
        public readonly string $totalDebit,
        public readonly string $totalCredit,
        public readonly string $closing,
    ) {}

    /**
     * @param  list<Account>  $accounts
     */
    public static function generate(
        AccountService $accountService,
        BalanceService $balances,
        array $accounts,
        ?Ref $party = null,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
        ?string $segment = null,
        ?NormalSide $side = null,
        ?int $limit = null,
    ): self {
        $side ??= $accounts[0]->normal_side;
        $ids = array_values(array_unique(array_merge(...array_map(fn (Account $a) => $accountService->descendantIds($a), $accounts))));

        $opening = Amount::zero();

        if ($from) {
            $before = $balances->entryTotals($ids, $party, $from->copy()->subSecond(), $segment)->first();
            $opening = $side->balance(Amount::of($before->debit ?? 0), Amount::of($before->credit ?? 0));
        }

        $running = $opening;
        $totalDebit = Amount::zero();
        $totalCredit = Amount::zero();
        $lines = [];

        $query = Entry::query()
            ->with('journal:id,number,event,description,source_type,source_id')
            ->whereIn('account_id', $ids)
            ->when($party, fn ($q) => $q->where('party_type', $party->type)->where('party_id', $party->id))
            ->when($from, fn ($q) => $q->where('occurred_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('occurred_at', '<=', $to))
            ->when($segment !== null, fn ($q) => $q->where('segment', $segment))
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->when($limit, fn ($q) => $q->limit($limit));

        foreach ($query->cursor() as $entry) {
            $debit = Amount::of($entry->debit);
            $credit = Amount::of($entry->credit);
            $running = $running->plus($side->balance($debit, $credit));
            $totalDebit = $totalDebit->plus($debit);
            $totalCredit = $totalCredit->plus($credit);
            $account = $accountService->find((int) $entry->account_id);

            $lines[] = [
                'entry_id' => $entry->id,
                'date' => $entry->occurred_at->toIso8601String(),
                'journal_id' => $entry->journal_id,
                'journal_number' => $entry->journal?->number,
                'event' => $entry->journal?->event,
                'description' => $entry->memo ?? $entry->journal?->description,
                'account' => $account->code,
                'party' => $entry->party()?->toArray(),
                'tag' => $entry->tag,
                'debit' => (string) $debit,
                'credit' => (string) $credit,
                'balance' => (string) $running,
            ];
        }

        return new self(
            array_map(fn (Account $a) => $a->code, $accounts),
            $party, $side, $from, $to,
            (string) $opening, $lines, (string) $totalDebit, (string) $totalCredit, (string) $running,
        );
    }

    public function toArray(): array
    {
        return [
            'accounts' => $this->accounts,
            'party' => $this->party?->toArray(),
            'side' => $this->side->value,
            'from' => $this->from?->toIso8601String(),
            'to' => $this->to?->toIso8601String(),
            'opening_balance' => $this->opening,
            'lines' => $this->lines,
            'total_debit' => $this->totalDebit,
            'total_credit' => $this->totalCredit,
            'closing_balance' => $this->closing,
        ];
    }
}
