<?php

namespace Alimrun\Ledger\Tests\Feature;

use Alimrun\Ledger\Facades\Ledger;
use Alimrun\Ledger\Tests\Fixtures\OrderDeliveredPosting;
use Alimrun\Ledger\Tests\TestCase;
use Illuminate\Support\Facades\Gate;

class ApiTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('ledger.routes.enabled', true);
        $app['config']->set('ledger.routes.middleware', []);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Gate::define('viewLedger', fn ($user = null) => true);
        Gate::define('manageLedger', fn ($user = null) => true);
    }

    public function test_reports_and_journals_are_served(): void
    {
        Ledger::post(new OrderDeliveredPosting);

        $this->getJson('/api/ledger/reports/trial-balance')->assertOk()->assertJsonPath('balanced', true);
        $this->getJson('/api/ledger/reports/income-statement')->assertOk()->assertJsonPath('net_income', '-3.000000000000');
        $this->getJson('/api/ledger/reports/balance-sheet')->assertOk()->assertJsonPath('balanced', true);
        $this->getJson('/api/ledger/reports/party-balances/2100')->assertOk()->assertJsonPath('rows.0.balance', '205.000000000000');
        $this->getJson('/api/ledger/reports/statement?accounts[]=2100&party_type=seller&party_id=44')->assertOk()->assertJsonPath('closing_balance', '205.000000000000');
        $this->getJson('/api/ledger/journals?party_type=seller&party_id=44')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/ledger/accounts/4000')->assertOk()->assertJsonPath('data.balance', '17.000000000000');
    }

    public function test_a_manual_journal_can_be_posted_and_reversed(): void
    {
        $response = $this->postJson('/api/ledger/journals', [
            'idempotency_key' => 'rent-2026-10',
            'description' => 'October rent',
            'lines' => [
                ['account' => '5405', 'debit' => '1200.50'],
                ['account' => '1020', 'credit' => '1200.50'],
            ],
        ])->assertCreated()->assertJsonPath('data.origin', 'manual');

        $this->assertAmount('1200.500000000000', Ledger::balance('5405'));

        $this->postJson('/api/ledger/journals/'.$response->json('data.id').'/reverse', ['reason' => 'Booked twice'])->assertCreated();

        $this->assertAmount('0.000000000000', Ledger::balance('5405'));
    }

    public function test_an_unbalanced_manual_journal_is_rejected(): void
    {
        $this->withoutExceptionHandling();
        $this->expectExceptionMessage('does not balance');

        $this->postJson('/api/ledger/journals', [
            'idempotency_key' => 'bad',
            'lines' => [
                ['account' => '5405', 'debit' => '10'],
                ['account' => '1020', 'credit' => '9'],
            ],
        ]);
    }

    public function test_access_is_denied_without_the_gate(): void
    {
        Gate::define('viewLedger', fn ($user = null) => false);

        $this->getJson('/api/ledger/reports/trial-balance')->assertForbidden();
    }
}
