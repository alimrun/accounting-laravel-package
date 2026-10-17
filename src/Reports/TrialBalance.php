<?php

namespace Alimrun\Ledger\Reports;

use Alimrun\Ledger\Models\Entry;
use Alimrun\Ledger\Services\AccountService;
use Alimrun\Ledger\Support\Amount;
use Carbon\CarbonInterface;

/**
 * Every account's debit and credit totals and its closing balance, placed
 * in the debit or credit column. The two columns are always equal.
 */
final class TrialBalance extends Report
{
    /**
     * @param  list<array{account_id: int, code: string, name: string, type: string, debit: string, credit: string, balance_debit: string, balance_credit: string}>  $rows
     */
    public function __construct(
        public readonly ?CarbonInterface $from,
        public readonly ?CarbonInterface $to,
        public readonly ?string $segment,
        public readonly array $rows,
        public readonly string $totalDebit,
        public readonly string $totalCredit,
    ) {}

    public static function generate(AccountService $accounts, ?CarbonInterface $to = null, ?string $segment = null, ?CarbonInterface $from = null, bool $includeZero = false): self
    {
        $totals = Entry::query()->toBase()
            ->when($from, fn ($q) => $q->where('occurred_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('occurred_at', '<=', $to))
            ->when($segment !== null, fn ($q) => $q->where('segment', $segment))
            ->selectRaw('account_id, SUM(debit) as debit, SUM(credit) as credit')
            ->groupBy('account_id')
            ->get()
            ->keyBy('account_id');

        $rows = [];
        $totalDebit = Amount::zero();
        $totalCredit = Amount::zero();

        foreach ($accounts->all() as $account) {
            if ($account->is_group) {
                continue;
            }

            $debit = Amount::of($totals[$account->id]->debit ?? 0);
            $credit = Amount::of($totals[$account->id]->credit ?? 0);
            $net = $debit->minus($credit);

            if (! $includeZero && $debit->isZero() && $credit->isZero()) {
                continue;
            }

            $balanceDebit = $net->isPositive() ? $net : Amount::zero();
            $balanceCredit = $net->isNegative() ? $net->negated() : Amount::zero();
            $totalDebit = $totalDebit->plus($balanceDebit);
            $totalCredit = $totalCredit->plus($balanceCredit);

            $rows[] = [
                'account_id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type->value,
                'debit' => (string) $debit,
                'credit' => (string) $credit,
                'balance_debit' => (string) $balanceDebit,
                'balance_credit' => (string) $balanceCredit,
            ];
        }

        return new self($from, $to, $segment, $rows, (string) $totalDebit, (string) $totalCredit);
    }

    public function isBalanced(): bool
    {
        return Amount::of($this->totalDebit)->isEqualTo(Amount::of($this->totalCredit));
    }

    public function toArray(): array
    {
        return [
            'from' => $this->from?->toIso8601String(),
            'to' => $this->to?->toIso8601String(),
            'segment' => $this->segment,
            'rows' => $this->rows,
            'total_debit' => $this->totalDebit,
            'total_credit' => $this->totalCredit,
            'balanced' => $this->isBalanced(),
        ];
    }
}
