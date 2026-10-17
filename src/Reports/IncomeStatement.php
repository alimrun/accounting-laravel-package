<?php

namespace Alimrun\Ledger\Reports;

use Alimrun\Ledger\Enums\AccountType;
use Alimrun\Ledger\Enums\JournalOrigin;
use Alimrun\Ledger\Models\Entry;
use Alimrun\Ledger\Services\AccountService;
use Alimrun\Ledger\Support\Amount;
use Carbon\CarbonInterface;

/**
 * Profit and loss for a date range. Closing journals are left out, so a
 * closed period still shows what it earned.
 */
final class IncomeStatement extends Report
{
    /**
     * @param  list<array{code: string, name: string, parent_code: string|null, amount: string}>  $revenue
     * @param  list<array{code: string, name: string, parent_code: string|null, amount: string}>  $expenses
     */
    public function __construct(
        public readonly ?CarbonInterface $from,
        public readonly ?CarbonInterface $to,
        public readonly ?string $segment,
        public readonly array $revenue,
        public readonly array $expenses,
        public readonly string $totalRevenue,
        public readonly string $totalExpenses,
        public readonly string $netIncome,
    ) {}

    public static function generate(AccountService $accounts, ?CarbonInterface $from = null, ?CarbonInterface $to = null, ?string $segment = null): self
    {
        $all = $accounts->all();
        $ids = $all->filter(fn ($a) => $a->type->isIncomeStatement())->keys()->all();

        $totals = Entry::query()->toBase()
            ->whereIn('account_id', $ids ?: [0])
            ->where('origin', '!=', JournalOrigin::Closing->value)
            ->when($from, fn ($q) => $q->where('occurred_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('occurred_at', '<=', $to))
            ->when($segment !== null, fn ($q) => $q->where('segment', $segment))
            ->selectRaw('account_id, SUM(debit) as debit, SUM(credit) as credit')
            ->groupBy('account_id')
            ->get();

        $sections = [AccountType::Revenue->value => [], AccountType::Expense->value => []];
        $sums = [AccountType::Revenue->value => Amount::zero(), AccountType::Expense->value => Amount::zero()];

        foreach ($totals as $row) {
            $account = $all->get($row->account_id);
            $debit = Amount::of($row->debit);
            $credit = Amount::of($row->credit);

            // Section-signed: contra accounts (discounts, returns) come out negative.
            $amount = $account->type === AccountType::Revenue ? $credit->minus($debit) : $debit->minus($credit);

            $sections[$account->type->value][] = [
                'code' => $account->code,
                'name' => $account->name,
                'parent_code' => $account->parent_id ? $all->get($account->parent_id)?->code : null,
                'amount' => (string) $amount,
            ];
            $sums[$account->type->value] = $sums[$account->type->value]->plus($amount);
        }

        foreach ($sections as &$rows) {
            usort($rows, fn ($a, $b) => strcmp($a['code'], $b['code']));
        }

        $revenue = $sums[AccountType::Revenue->value];
        $expenses = $sums[AccountType::Expense->value];

        return new self(
            $from, $to, $segment,
            $sections[AccountType::Revenue->value],
            $sections[AccountType::Expense->value],
            (string) $revenue,
            (string) $expenses,
            (string) $revenue->minus($expenses),
        );
    }

    public function toArray(): array
    {
        return [
            'from' => $this->from?->toIso8601String(),
            'to' => $this->to?->toIso8601String(),
            'segment' => $this->segment,
            'revenue' => $this->revenue,
            'expenses' => $this->expenses,
            'total_revenue' => $this->totalRevenue,
            'total_expenses' => $this->totalExpenses,
            'net_income' => $this->netIncome,
        ];
    }
}
