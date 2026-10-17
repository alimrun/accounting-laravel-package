<?php

namespace Alimrun\Ledger\Tests\Feature;

use Alimrun\Ledger\Events\JournalReversed;
use Alimrun\Ledger\Exceptions\ImmutableRecordException;
use Alimrun\Ledger\Facades\Ledger;
use Alimrun\Ledger\Models\Entry;
use Alimrun\Ledger\Models\Journal;
use Alimrun\Ledger\Tests\Fixtures\OrderDeliveredPosting;
use Alimrun\Ledger\Tests\TestCase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

class ImmutabilityAndReversalTest extends TestCase
{
    public function test_journals_cannot_be_updated_through_eloquent(): void
    {
        $journal = Ledger::post(new OrderDeliveredPosting);

        $this->expectException(ImmutableRecordException::class);

        $journal->update(['description' => 'edited']);
    }

    public function test_entries_cannot_be_deleted_through_eloquent(): void
    {
        $journal = Ledger::post(new OrderDeliveredPosting);

        $this->expectException(ImmutableRecordException::class);

        $journal->entries->first()->delete();
    }

    public function test_database_triggers_refuse_raw_updates_and_deletes(): void
    {
        $journal = Ledger::post(new OrderDeliveredPosting);

        foreach ([
            fn () => DB::table('ledger_entries')->where('journal_id', $journal->id)->update(['debit' => 1]),
            fn () => DB::table('ledger_entries')->where('journal_id', $journal->id)->delete(),
            fn () => DB::table('ledger_journals')->where('id', $journal->id)->update(['description' => 'x']),
            fn () => DB::table('ledger_journals')->where('id', $journal->id)->delete(),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A raw write to the ledger was allowed.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('immutable', $e->getMessage());
            }
        }
    }

    public function test_a_reversal_mirrors_the_journal_and_nets_balances_to_zero(): void
    {
        Event::fake([JournalReversed::class]);

        $journal = Ledger::post(new OrderDeliveredPosting);
        $reversal = Ledger::reverse($journal, 'Order returned');

        $this->assertSame('order.delivered.reversal', $reversal->event);
        $this->assertSame($journal->id, $reversal->reversal_of_id);
        $this->assertTrue($journal->isReversed());
        $this->assertTrue($reversal->isReversal());
        $this->assertAmount('0.000000000000', Ledger::balance('2100', $this->seller()));
        $this->assertAmount('0.000000000000', Ledger::balance('4000'));
        $this->assertSame(
            $journal->entries->pluck('debit')->all(),
            $reversal->entries->pluck('credit')->all(),
        );

        Event::assertDispatched(JournalReversed::class);
    }

    public function test_reversing_twice_returns_the_first_reversal(): void
    {
        $journal = Ledger::post(new OrderDeliveredPosting);

        $first = Ledger::reverse($journal);
        $second = Ledger::reverse($journal->id);

        $this->assertTrue($first->is($second));
        $this->assertSame(2, Journal::query()->count());
        $this->assertSame(8, Entry::query()->count());
    }

    public function test_a_reversal_goes_through_after_the_account_is_deactivated(): void
    {
        $journal = Ledger::post(new OrderDeliveredPosting);
        Ledger::accounts()->deactivate(Ledger::account('5000'));

        $this->assertNotNull(Ledger::reverse($journal));
    }
}
