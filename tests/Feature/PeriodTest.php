<?php

namespace Alimrun\Ledger\Tests\Feature;

use Alimrun\Ledger\Enums\JournalOrigin;
use Alimrun\Ledger\Exceptions\LedgerException;
use Alimrun\Ledger\Exceptions\PeriodClosedException;
use Alimrun\Ledger\Facades\Ledger;
use Alimrun\Ledger\Tests\TestCase;

class PeriodTest extends TestCase
{
    protected function seedSeptember(): void
    {
        Ledger::journal('cash.sale')->key('sale:1')->occurredAt('2026-09-10')->segment('retail')
            ->debit('1000', 500)->credit('4010', 500)->post();

        Ledger::journal('expense.salary')->key('salary:2026-09')->occurredAt('2026-09-28')
            ->debit('5401', 300)->credit('1020', 300)->post();
    }

    public function test_closing_moves_income_and_expenses_into_retained_earnings(): void
    {
        $this->seedSeptember();

        $closing = Ledger::closePeriod('2026-09');

        $this->assertSame(JournalOrigin::Closing, $closing->origin);
        $this->assertSame('2026-09', $closing->period);
        $this->assertAmount('0.000000', Ledger::balance('4010'));
        $this->assertAmount('0.000000', Ledger::balance('5401'));
        $this->assertAmount('200.000000', Ledger::balance('3100'));
        $this->assertTrue(Ledger::periods()->isClosed('2026-09'));
        $this->assertSame([], Ledger::verify());
    }

    public function test_a_closed_period_refuses_postings(): void
    {
        $this->seedSeptember();
        Ledger::closePeriod('2026-09');

        $this->expectException(PeriodClosedException::class);

        Ledger::journal('cash.sale')->key('sale:late')->occurredAt('2026-09-30')
            ->debit('1000', 5)->credit('4010', 5)->post();
    }

    public function test_the_income_statement_still_shows_a_closed_period(): void
    {
        $this->seedSeptember();
        Ledger::closePeriod('2026-09');

        $pl = Ledger::incomeStatement('2026-09-01', '2026-09-30 23:59:59');

        $this->assertAmount('500.000000', $pl->totalRevenue);
        $this->assertAmount('300.000000', $pl->totalExpenses);
        $this->assertAmount('200.000000', $pl->netIncome);
        $this->assertSame('5400', $pl->expenses[0]['parent_code']);
    }

    public function test_the_balance_sheet_balances_before_and_after_closing(): void
    {
        $this->seedSeptember();
        Ledger::openingBalance('1020', 1000);

        $before = Ledger::balanceSheet();
        $this->assertTrue($before->isBalanced());
        $this->assertAmount('200.000000', $before->currentEarnings);

        Ledger::closePeriod('2026-09');

        $after = Ledger::balanceSheet();
        $this->assertTrue($after->isBalanced());
        $this->assertAmount('0.000000', $after->currentEarnings);
        $this->assertAmount('1200.000000', $after->totalAssets);
    }

    public function test_reopening_reverses_the_close_and_allows_postings_again(): void
    {
        $this->seedSeptember();
        Ledger::closePeriod('2026-09');
        Ledger::reopenPeriod('2026-09');

        $this->assertFalse(Ledger::periods()->isClosed('2026-09'));
        $this->assertAmount('500.000000', Ledger::balance('4010'));
        $this->assertAmount('0.000000', Ledger::balance('3100'));

        Ledger::journal('cash.sale')->key('sale:late')->occurredAt('2026-09-30')
            ->debit('1000', 50)->credit('4010', 50)->post();

        Ledger::closePeriod('2026-09');
        $this->assertAmount('250.000000', Ledger::balance('3100'));
        $this->assertSame([], Ledger::verify());
    }

    public function test_closing_twice_is_refused(): void
    {
        Ledger::closePeriod('2026-08');

        $this->expectException(LedgerException::class);

        Ledger::closePeriod('2026-08');
    }

    public function test_period_format_is_checked(): void
    {
        $this->expectException(LedgerException::class);

        Ledger::closePeriod('2026-13');
    }
}
