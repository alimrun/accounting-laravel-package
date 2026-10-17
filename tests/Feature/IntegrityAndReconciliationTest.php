<?php

namespace Alimrun\Ledger\Tests\Feature;

use Alimrun\Ledger\Contracts\Reconciler;
use Alimrun\Ledger\Facades\Ledger;
use Alimrun\Ledger\Models\ReconciliationIssue;
use Alimrun\Ledger\Support\Expectation;
use Alimrun\Ledger\Tests\Fixtures\Customer;
use Alimrun\Ledger\Tests\Fixtures\OrderDeliveredPosting;
use Alimrun\Ledger\Tests\TestCase;
use Illuminate\Support\Facades\DB;

class WalletReconciler implements Reconciler
{
    public function name(): string
    {
        return 'customer-wallet';
    }

    public function expectations(): iterable
    {
        foreach (Customer::query()->get() as $customer) {
            yield new Expectation('2000', $customer, $customer->wallet_balance, 'customers.wallet_balance');
        }
    }
}

class IntegrityAndReconciliationTest extends TestCase
{
    public function test_verify_reports_a_tampered_balance_and_rebuild_repairs_it(): void
    {
        Ledger::post(new OrderDeliveredPosting);
        $this->assertSame([], Ledger::verify());

        DB::table('ledger_balances')->where('party_type', 'seller')->update(['credit_total' => '999']);

        $problems = Ledger::verify();
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('Balance row', $problems[0]);

        $this->assertSame(2, Ledger::rebuildBalances());
        $this->assertSame([], Ledger::verify());
    }

    public function test_reconciliation_records_and_resolves_differences(): void
    {
        $customer = Customer::query()->create(['name' => 'Karim', 'wallet_balance' => 120]);

        Ledger::journal('wallet.topup')->key('t:1')
            ->debit('1010', 100)->credit('2000', 100, party: $customer)->post();

        $summary = Ledger::reconcile([WalletReconciler::class]);

        $this->assertSame(['customer-wallet' => ['checked' => 1, 'differences' => 1]], $summary);
        $issue = ReconciliationIssue::query()->unresolved()->sole();
        $this->assertAmount('20.000000', $issue->difference);

        // A second run updates the same issue instead of adding one.
        Ledger::reconcile([WalletReconciler::class]);
        $this->assertSame(1, ReconciliationIssue::query()->count());

        Ledger::journal('wallet.topup')->key('t:2')
            ->debit('1010', 20)->credit('2000', 20, party: $customer)->post();

        Ledger::reconcile([WalletReconciler::class]);
        $this->assertSame(0, ReconciliationIssue::query()->unresolved()->count());
    }

    public function test_commands_run(): void
    {
        Ledger::post(new OrderDeliveredPosting);

        $this->artisan('ledger:verify')->assertSuccessful();
        $this->artisan('ledger:trial-balance')->expectsOutputToContain('Balanced')->assertSuccessful();
        $this->artisan('ledger:close-period', ['period' => '2026-10'])->assertSuccessful();
        $this->artisan('ledger:reopen-period', ['period' => '2026-10'])->assertSuccessful();
        $this->artisan('ledger:rebuild-balances', ['--force' => true])->assertSuccessful();
        $this->artisan('ledger:reconcile')->assertSuccessful();
        $this->artisan('ledger:seed-chart', ['chart' => 'marketplace'])->assertSuccessful();
    }
}
