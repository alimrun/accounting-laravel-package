<?php

namespace Alimrun\Ledger\Enums;

enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Revenue = 'revenue';
    case Expense = 'expense';

    public function normalSide(): NormalSide
    {
        return match ($this) {
            self::Asset, self::Expense => NormalSide::Debit,
            self::Liability, self::Equity, self::Revenue => NormalSide::Credit,
        };
    }

    public function isBalanceSheet(): bool
    {
        return in_array($this, [self::Asset, self::Liability, self::Equity], true);
    }

    public function isIncomeStatement(): bool
    {
        return ! $this->isBalanceSheet();
    }

    public function label(): string
    {
        return match ($this) {
            self::Asset => 'Assets',
            self::Liability => 'Liabilities',
            self::Equity => 'Equity',
            self::Revenue => 'Revenue',
            self::Expense => 'Expenses',
        };
    }
}
