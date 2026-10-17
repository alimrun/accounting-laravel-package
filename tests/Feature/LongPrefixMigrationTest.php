<?php

namespace Alimrun\Ledger\Tests\Feature;

use Alimrun\Ledger\Facades\Ledger;
use Alimrun\Ledger\Tests\Fixtures\OrderDeliveredPosting;
use Alimrun\Ledger\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * MySQL refuses identifiers longer than 64 characters (error 1059), so every
 * index and trigger name must stay within that even with a long prefix.
 */
class LongPrefixMigrationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('ledger.database.table_prefix', 'acme_holdings_general_ledger_');
    }

    public function test_every_generated_identifier_fits_mysqls_64_character_limit(): void
    {
        $names = DB::table('sqlite_master')
            ->whereIn('type', ['index', 'trigger'])
            ->where('name', 'not like', 'sqlite_autoindex%')
            ->where('tbl_name', 'like', 'acme_holdings_general_ledger_%')
            ->pluck('name');

        $this->assertGreaterThan(20, $names->count());
        $this->assertSame([], $names->filter(fn ($name) => strlen($name) > 64)->values()->all());
        $this->assertSame($names->count(), $names->unique()->count(), 'shortened names must stay unique');
    }

    public function test_the_ledger_works_with_a_long_prefix(): void
    {
        Ledger::post(new OrderDeliveredPosting);

        $this->assertTrue(DB::getSchemaBuilder()->hasTable('acme_holdings_general_ledger_entries'));
        $this->assertSame([], Ledger::verify());
    }
}
