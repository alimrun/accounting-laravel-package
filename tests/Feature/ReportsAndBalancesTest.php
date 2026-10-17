<?php

namespace Alimrun\Ledger\Tests\Feature;

use Alimrun\Ledger\Enums\NormalSide;
use Alimrun\Ledger\Exceptions\LedgerException;
use Alimrun\Ledger\Facades\Ledger;
use Alimrun\Ledger\Models\Balance;
use Alimrun\Ledger\Tests\Fixtures\Customer;
use Alimrun\Ledger\Tests\Fixtures\OrderDeliveredPosting;
use Alimrun\Ledger\Tests\TestCase;
use Illuminate\Support\Facades\DB;

class ReportsAndBalancesTest extends TestCase
{
    public function test_trial_balance_nets_to_zero(): void
    {
        Ledger::post(new OrderDeliveredPosting(1));
        Ledger::post(new OrderDeliveredPosting(2));

        $tb = Ledger::trialBalance();

        $this->assertTrue($tb->isBalanced());
        $this->assertAmount('444.000000', $tb->totalDebit);
        $this->assertSame(['2050', '2100', '4000', '5000'], array_column($tb->rows, 'code'));
    }

    public function test_vendor_withdrawable_combines_payable_and_cash_held(): void
    {
        Ledger::post(new OrderDeliveredPosting);

        // Seller collected 50 in cash on another order and owes it to the platform.
        Ledger::journal('order.delivered.cod')->key('order:7:delivered')
            ->debit('1100', 50, party: $this->seller())
            ->credit('2100', 45, party: $this->seller())
            ->credit('4000', 5)
            ->post();

        // withdrawable = credit balance of 2100 - debit balance of 1100
        $this->assertAmount('200.000000', Ledger::balance(['2100', '1100'], $this->seller(), side: NormalSide::Credit));
    }

    public function test_balance_as_of_a_date_reads_entries(): void
    {
        Ledger::journal('wallet.topup')->key('t:1')->occurredAt('2026-01-10')
            ->debit('1010', 100)->credit('2000', 100, party: $this->customer())->post();
        Ledger::journal('wallet.topup')->key('t:2')->occurredAt('2026-02-10')
            ->debit('1010', 40)->credit('2000', 40, party: $this->customer())->post();

        $this->assertAmount('100.000000', Ledger::balance('2000', $this->customer(), '2026-01-31'));
        $this->assertAmount('140.000000', Ledger::balance('2000', $this->customer()));
        $this->assertAmount('140.000000', Ledger::balance('1010', asOf: '2026-12-31'));
    }

    public function test_customer_statement_has_running_balances(): void
    {
        Ledger::journal('wallet.topup')->key('t:1')->occurredAt('2026-01-10')
            ->debit('1010', 100)->credit('2000', 100, party: $this->customer())->post();
        Ledger::journal('order.paid')->key('o:1')->occurredAt('2026-02-01')
            ->debit('2000', 30, party: $this->customer())->credit('2050', 30, party: $this->customer())->post();
        Ledger::journal('wallet.topup')->key('t:2')->occurredAt('2026-03-01')
            ->debit('1010', 10)->credit('2000', 10, party: $this->customer())->post();

        $statement = Ledger::statement('2000', $this->customer(), from: '2026-02-01');

        $this->assertAmount('100.000000', $statement->opening);
        $this->assertSame(['70.000000', '80.000000'], array_column($statement->lines, 'balance'));
        $this->assertAmount('80.000000', $statement->closing);
        $this->assertNotNull($statement->lines[0]['journal_number']);
    }

    public function test_party_balances_list_the_sub_ledger(): void
    {
        foreach ([1 => 30, 2 => 70, 3 => 0] as $id => $amount) {
            Ledger::journal('wallet.topup')->key("t:{$id}")
                ->debit('1010', $amount)->credit('2000', $amount, party: $this->customer($id))->when($amount === 0, fn ($j) => $j->debit('1010', 1)->credit('2000', 1, party: $this->customer($id)))
                ->post();
        }

        $report = Ledger::partyBalances('2000');

        $this->assertSame(['2', '1', '3'], array_column($report->rows, 'party_id'));
        $this->assertAmount('101.000000', $report->total);
    }

    public function test_has_ledger_trait_reads_a_models_balance_and_statement(): void
    {
        $customer = Customer::query()->create(['name' => 'Rahim']);

        Ledger::journal('wallet.topup')->key('t:1')
            ->debit('1010', 25)->credit('2000', 25, party: $customer)->post();

        $this->assertAmount('25.000000', $customer->ledgerBalance('2000'));
        $this->assertSame(1, $customer->ledgerEntries()->count());
        $this->assertCount(1, $customer->ledgerStatement('2000')->lines);
    }

    public function test_lock_balance_inside_a_wallet_payment(): void
    {
        Ledger::journal('wallet.topup')->key('t:1')
            ->debit('1010', 50)->credit('2000', 50, party: $this->customer())->post();

        $paid = DB::transaction(function () {
            $available = Ledger::lockBalance('2000', $this->customer());

            if ($available->isLessThan(40)) {
                return false;
            }

            Ledger::journal('order.paid')->key('order:9:paid')
                ->debit('2000', 40, party: $this->customer())
                ->credit('2050', 40, party: $this->customer())
                ->post();

            return true;
        });

        $this->assertTrue($paid);
        $this->assertAmount('10.000000', Ledger::balance('2000', $this->customer()));
    }

    public function test_lock_balance_needs_a_tracked_row(): void
    {
        $this->expectException(LedgerException::class);

        Ledger::lockBalance('4000');
    }

    public function test_tracking_all_balances_keeps_rows_for_platform_accounts(): void
    {
        config(['ledger.balances.track' => 'all']);

        Ledger::post(new OrderDeliveredPosting);

        $this->assertSame(4, Balance::query()->count());
        $this->assertAmount('17.000000', Ledger::balance('4000'));
        $this->assertAmount('17.000000', Ledger::lockBalance('4000'));
        $this->assertSame([], Ledger::verify());
    }

    public function test_group_account_balance_includes_children(): void
    {
        Ledger::journal('opex')->key('opex:1')->debit('5401', 100)->credit('1020', 100)->post();
        Ledger::journal('opex')->key('opex:2')->debit('5402', 40)->credit('1020', 40)->post();

        $this->assertAmount('140.000000', Ledger::balance('5400'));
    }

    public function test_opening_balances_post_against_opening_equity(): void
    {
        $asset = Ledger::openingBalance('1100', 300, $this->seller(), '2026-01-01');
        $liability = Ledger::openingBalance('2000', 80, $this->customer(), '2026-01-01');
        $again = Ledger::openingBalance('2000', 80, $this->customer(), '2026-01-01');

        $this->assertTrue($liability->is($again));
        $this->assertAmount('300.000000', Ledger::balance('1100', $this->seller()));
        $this->assertAmount('80.000000', Ledger::balance('2000', $this->customer()));
        // Opening equity takes the net: 300 of assets less 80 owed to customers.
        $this->assertAmount('220.000000', Ledger::balance('3000'));
        $this->assertSame('opening', $asset->origin->value);
    }

    public function test_reports_serialise_to_json(): void
    {
        Ledger::post(new OrderDeliveredPosting);

        $json = json_decode(json_encode([
            'tb' => Ledger::trialBalance(),
            'pl' => Ledger::incomeStatement(),
            'bs' => Ledger::balanceSheet(),
        ]), true);

        $this->assertTrue($json['tb']['balanced']);
        $this->assertSame('-3.000000', $json['pl']['net_income']);
        $this->assertTrue($json['bs']['balanced']);
    }
}
