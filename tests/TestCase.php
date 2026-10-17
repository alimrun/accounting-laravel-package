<?php

namespace Alimrun\Ledger\Tests;

use Alimrun\Ledger\Facades\Ledger;
use Alimrun\Ledger\LedgerServiceProvider;
use Alimrun\Ledger\Tests\Fixtures\Customer;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [LedgerServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Ledger' => Ledger::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('ledger.database.immutable_triggers', true);
        $app['config']->set('ledger.chart', 'marketplace');
    }

    protected function afterRefreshingDatabase(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('wallet_balance', 30, 12)->default(0);
        });

        Ledger::accounts()->flush();
        Ledger::accounts()->seed('marketplace');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Relation::morphMap([
            'customer' => Customer::class,
        ]);
    }

    protected function customer(int $id = 812): array
    {
        return ['customer', $id];
    }

    protected function seller(int $id = 44): array
    {
        return ['seller', $id];
    }

    protected function deliveryMan(int $id = 7): array
    {
        return ['delivery_man', $id];
    }

    protected function assertAmount(string $expected, $actual, string $message = ''): void
    {
        $this->assertSame($expected, (string) $actual, $message);
    }
}
