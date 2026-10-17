<?php

namespace Alimrun\Ledger\Reports;

use Alimrun\Ledger\Enums\AccountType;
use Alimrun\Ledger\Models\Entry;
use Alimrun\Ledger\Services\AccountService;
use Alimrun\Ledger\Support\Amount;
use Carbon\CarbonInterface;

/**
 * Assets, liabilities and equity at a date. Earnings not yet closed into
 * retained earnings appear as "current earnings" so the sheet balances.
 */
final class BalanceSheet extends Report
{
    public function __construct(
        public readonly ?CarbonInterface $asOf,
        public readonly ?string $segment,
        public readonly array $assets,
        public readonly array $liabilities,
        public readonly array $equity,
        public readonly string $totalAssets,
        public readonly string $totalLiabilities,
        public readonly string $totalEquity,
        public readonly string $currentEarnings,
    ) {}

    public static function generate(AccountService $accounts, ?CarbonInterface $asOf = null, ?string $segment = null): self
    {
        $all = $accounts->all();

        $totals = Entry::query()->toBase()
            ->when($asOf, fn ($q) => $q->where('occurred_at', '<=', $asOf))
            ->when($segment !== null, fn ($q) => $q->where('segment', $segment))
            ->selectRaw('account_id, SUM(debit) as debit, SUM(credit) as credit')
            ->groupBy('account_id')
            ->get();

        $sections = ['asset' => [], 'liability' => [], 'equity' => []];
        $sums = ['asset' => Amount::zero(), 'liability' => Amount::zero(), 'equity' => Amount::zero()];
        $earnings = Amount::zero();

        foreach ($totals as $row) {
            $account = $all->get($row->account_id);
            $debit = Amount::of($row->debit);
            $credit = Amount::of($row->credit);

            if ($account->type->isIncomeStatement()) {
                $earnings = $earnings->plus($credit->minus($debit));

                continue;
            }

            $amount = $account->type === AccountType::Asset ? $debit->minus($credit) : $credit->minus($debit);

            if ($amount->isZero()) {
                continue;
            }

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

        return new self(
            $asOf, $segment,
            $sections['asset'], $sections['liability'], $sections['equity'],
            (string) $sums['asset'],
            (string) $sums['liability'],
            (string) $sums['equity']->plus($earnings),
            (string) $earnings,
        );
    }

    public function isBalanced(): bool
    {
        return Amount::of($this->totalAssets)->isEqualTo(Amount::of($this->totalLiabilities)->plus(Amount::of($this->totalEquity)));
    }

    public function toArray(): array
    {
        return [
            'as_of' => $this->asOf?->toIso8601String(),
            'segment' => $this->segment,
            'assets' => $this->assets,
            'liabilities' => $this->liabilities,
            'equity' => $this->equity,
            'current_earnings' => $this->currentEarnings,
            'total_assets' => $this->totalAssets,
            'total_liabilities' => $this->totalLiabilities,
            'total_equity' => $this->totalEquity,
            'balanced' => $this->isBalanced(),
        ];
    }
}
