<?php

namespace Alimrun\Ledger\Tests\Feature;

use Alimrun\Ledger\Exceptions\IdempotencyConflictException;
use Alimrun\Ledger\Exceptions\InvalidAmountException;
use Alimrun\Ledger\Exceptions\InvalidJournalException;
use Alimrun\Ledger\Exceptions\PartyMismatchException;
use Alimrun\Ledger\Exceptions\UnbalancedJournalException;
use Alimrun\Ledger\Facades\Ledger;
use Alimrun\Ledger\Models\Entry;
use Alimrun\Ledger\Models\Journal;
use Alimrun\Ledger\Tests\Fixtures\OrderDeliveredPosting;
use Alimrun\Ledger\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PostingTest extends TestCase
{
    protected function payOrder(): Journal
    {
        return Ledger::journal('order.paid')
            ->idempotencyKey('payment:5001:captured')
            ->source(['order', 100245])
            ->occurredAt('2026-10-01 10:00:00')
            ->paymentMethod('stripe')
            ->debit('1010', '202.00', tag: 'stripe')
            ->credit('2050', '202.00', party: $this->customer())
            ->post();
    }

    public function test_it_posts_the_delivered_order_voucher_from_the_plan(): void
    {
        $this->payOrder();
        $journal = Ledger::post(new OrderDeliveredPosting);

        $this->assertSame('JV-2026-00000002', $journal->number);
        $this->assertSame('2026-10', $journal->period);
        $this->assertSame('retail', $journal->segment);
        $this->assertCount(4, $journal->entries, 'the zero free-delivery line is skipped');
        $this->assertAmount('222.000000', $journal->totalDebit());
        $this->assertAmount('222.000000', $journal->totalCredit());

        $this->assertAmount('0.000000', Ledger::balance('2050', $this->customer()));
        $this->assertAmount('205.000000', Ledger::balance('2100', $this->seller()));
        $this->assertAmount('17.000000', Ledger::balance('4000'));
        $this->assertAmount('20.000000', Ledger::balance('5000'));
        $this->assertAmount('202.000000', Ledger::balance('1010'));
        $this->assertSame([], Ledger::verify());
    }

    public function test_lines_inherit_the_journal_segment(): void
    {
        $journal = Ledger::post(new OrderDeliveredPosting);

        $this->assertSame(['retail'], $journal->entries->pluck('segment')->unique()->values()->all());
        $this->assertAmount('17.000000', Ledger::balance('4000', segment: 'retail'));
        $this->assertAmount('0.000000', Ledger::balance('4000', segment: 'wholesale'));
    }

    public function test_an_unbalanced_journal_is_refused(): void
    {
        $this->expectException(UnbalancedJournalException::class);

        Ledger::journal('broken')->key('broken:1')
            ->debit('1000', 100)
            ->credit('4010', '99.99')
            ->post();
    }

    public function test_a_residual_within_tolerance_goes_to_the_rounding_account(): void
    {
        $journal = Ledger::journal('fx.sale')->key('fx:1')
            ->debit('1000', '100.000004')
            ->credit('4010', '100.000000')
            ->post();

        $rounding = $journal->entries->firstWhere('tag', 'rounding');

        $this->assertNotNull($rounding);
        $this->assertSame('5910', $rounding->account->code);
        $this->assertAmount('0.000004', $rounding->credit);
    }

    public function test_floats_are_rounded_to_six_places_and_balance_exactly(): void
    {
        $journal = Ledger::journal('float.sale')->key('float:1')
            ->debit('1000', 0.1 + 0.2)
            ->credit('4010', 0.3)
            ->post();

        $this->assertAmount('0.300000', $journal->entries[0]->debit);
        $this->assertCount(2, $journal->entries);
    }

    public function test_negative_amounts_are_refused(): void
    {
        $this->expectException(InvalidAmountException::class);

        Ledger::journal('neg')->key('neg:1')->debit('1000', -5);
    }

    public function test_a_single_line_journal_is_refused(): void
    {
        $this->expectException(InvalidJournalException::class);

        Ledger::journal('one')->key('one:1')->debit('1000', 0)->credit('4010', 0)->debit('1000', 0)->post();
    }

    public function test_group_accounts_cannot_be_posted_to(): void
    {
        $this->expectExceptionMessage('group account');

        Ledger::journal('opex')->key('opex:1')->debit('5400', 10)->credit('1000', 10)->post();
    }

    public function test_inactive_accounts_are_refused(): void
    {
        Ledger::accounts()->deactivate(Ledger::account('5300'));

        $this->expectExceptionMessage('inactive');

        Ledger::journal('fee')->key('fee:1')->debit('5300', 1)->credit('1010', 1)->post();
    }

    public function test_party_accounts_need_a_party(): void
    {
        $this->expectException(PartyMismatchException::class);

        Ledger::journal('x')->key('x:1')->debit('1000', 5)->credit('2100', 5)->post();
    }

    public function test_platform_accounts_refuse_a_party(): void
    {
        $this->expectException(PartyMismatchException::class);

        Ledger::journal('x')->key('x:2')->debit('1000', 5)->credit('4000', 5, party: $this->seller())->post();
    }

    public function test_party_type_must_match_the_account(): void
    {
        $this->expectException(PartyMismatchException::class);

        Ledger::journal('x')->key('x:3')->debit('1000', 5)->credit('2100', 5, party: $this->customer())->post();
    }

    public function test_an_idempotency_key_is_required(): void
    {
        $this->expectException(InvalidJournalException::class);

        Ledger::journal('x')->debit('1000', 5)->credit('4010', 5)->post();
    }

    public function test_replaying_a_key_returns_the_first_journal_and_moves_nothing(): void
    {
        $first = Ledger::post(new OrderDeliveredPosting);
        $second = Ledger::post(new OrderDeliveredPosting);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Journal::query()->count());
        $this->assertAmount('205.000000', Ledger::balance('2100', $this->seller()));
    }

    public function test_reusing_a_key_for_different_money_throws(): void
    {
        Ledger::journal('topup')->key('payment:1:captured')->debit('1010', 50)->credit('2000', 50, party: $this->customer())->post();

        $this->expectException(IdempotencyConflictException::class);

        Ledger::journal('topup')->key('payment:1:captured')->debit('1010', 60)->credit('2000', 60, party: $this->customer())->post();
    }

    public function test_the_kill_switch_writes_nothing(): void
    {
        Ledger::disable();

        $this->assertNull(Ledger::post(new OrderDeliveredPosting));
        $this->assertSame(0, Journal::query()->count());

        Ledger::enable();
        $this->assertNotNull(Ledger::post(new OrderDeliveredPosting));
    }

    public function test_a_failed_business_transaction_rolls_the_posting_back(): void
    {
        try {
            DB::transaction(function () {
                Ledger::post(new OrderDeliveredPosting);

                throw new RuntimeException('status update failed');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, Journal::query()->count());
        $this->assertSame(0, Entry::query()->count());
        $this->assertAmount('0.000000', Ledger::balance('2100', $this->seller()));

        // The sequence rolled back too, so numbering stays gapless.
        $this->assertSame('JV-2026-00000001', Ledger::post(new OrderDeliveredPosting)->number);
    }

    public function test_a_refused_posting_inside_a_transaction_leaves_the_outer_work_usable(): void
    {
        DB::transaction(function () {
            Ledger::post(new OrderDeliveredPosting(1));

            try {
                Ledger::journal('broken')->key('broken:2')->debit('1000', 1)->credit('4010', 2)->post();
            } catch (UnbalancedJournalException) {
            }

            Ledger::post(new OrderDeliveredPosting(2));
        });

        $this->assertSame(2, Journal::query()->count());
    }

    public function test_numbers_are_gapless_and_reset_each_year(): void
    {
        $numbers = collect([
            ['2026-03-01', 'a'], ['2026-04-01', 'b'], ['2027-01-02', 'c'], ['2026-12-31', 'd'],
        ])->map(fn ($row) => Ledger::journal('cash.sale')->key('sale:'.$row[1])->occurredAt($row[0])
            ->debit('1000', 10)->credit('4010', 10)->post()->number);

        $this->assertSame(['JV-2026-00000001', 'JV-2026-00000002', 'JV-2027-00000001', 'JV-2026-00000003'], $numbers->all());
    }

    public function test_fast_numbering_uses_the_journal_id(): void
    {
        config(['ledger.numbering.strategy' => 'fast', 'ledger.numbering.format' => 'J{number}', 'ledger.numbering.pad' => 4]);

        $journal = Ledger::journal('cash.sale')->key('sale:1')->debit('1000', 10)->credit('4010', 10)->post();

        $this->assertSame('J'.str_pad((string) $journal->id, 4, '0', STR_PAD_LEFT), $journal->number);
    }

    public function test_conditional_lines_with_when(): void
    {
        $withBonus = true;

        $journal = Ledger::journal('wallet.topup')->key('topup:9')
            ->debit('1010', 100)
            ->when($withBonus, fn ($j) => $j->debit('5100', 5, tag: 'bonus'))
            ->credit('2000', $withBonus ? 105 : 100, party: $this->customer())
            ->post();

        $this->assertCount(3, $journal->entries);
        $this->assertAmount('105.000000', Ledger::balance('2000', $this->customer()));
    }

    public function test_currency_and_metadata_are_kept_on_the_journal(): void
    {
        $journal = Ledger::journal('cash.sale')->key('sale:bdt')
            ->currency('bdt', '0.0085')
            ->description('Counter sale')
            ->transactionRef('TX-1')
            ->meta(['till' => 3])
            ->debit('1000', '8.50')->credit('4010', '8.50')
            ->post()
            ->fresh();

        $this->assertSame('BDT', $journal->currency);
        $this->assertSame('0.0085000000', $journal->exchange_rate);
        $this->assertSame(['till' => 3], $journal->meta);
        $this->assertSame('TX-1', $journal->transaction_ref);
    }
}
