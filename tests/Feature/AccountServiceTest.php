<?php

namespace Alimrun\Ledger\Tests\Feature;

use Alimrun\Ledger\Enums\NormalSide;
use Alimrun\Ledger\Exceptions\AccountNotFoundException;
use Alimrun\Ledger\Exceptions\LedgerException;
use Alimrun\Ledger\Facades\Ledger;
use Alimrun\Ledger\Models\Account;
use Alimrun\Ledger\Tests\TestCase;

class AccountServiceTest extends TestCase
{
    public function test_seeding_is_idempotent(): void
    {
        $count = Account::query()->count();

        $this->assertSame(0, Ledger::accounts()->seed('marketplace'));
        $this->assertSame($count, Account::query()->count());
        $this->assertSame('5910', Ledger::accounts()->findByRole('rounding')->code);
    }

    public function test_the_standard_chart_seeds_cleanly(): void
    {
        Account::query()->whereNotNull('parent_id')->delete();
        Account::query()->delete();
        Ledger::accounts()->flush();

        $this->assertGreaterThan(40, Ledger::accounts()->seed('standard'));
        $this->assertSame(NormalSide::Credit, Ledger::account('1610')->normal_side);
        $this->assertSame('3100', Ledger::accounts()->findByRole('retained_earnings')->code);
    }

    public function test_creating_an_account_with_a_parent_and_party_types(): void
    {
        $account = Ledger::accounts()->create([
            'code' => '5407',
            'name' => 'Training',
            'type' => 'expense',
            'parent' => '5400',
            'party' => ['employee'],
        ]);

        $this->assertSame(Ledger::account('5400')->id, $account->parent_id);
        $this->assertSame(['employee'], $account->partyTypes());
        $this->assertSame(NormalSide::Debit, $account->normal_side);
        $this->assertSame('5400', Ledger::accounts()->tree()->firstWhere('code', '5400')->children->first()->parent->code);
    }

    public function test_parent_must_be_a_group_of_the_same_type(): void
    {
        $this->expectException(LedgerException::class);

        Ledger::accounts()->create(['code' => '5999', 'name' => 'X', 'type' => 'expense', 'parent' => '5401']);
    }

    public function test_type_cannot_change_once_an_account_has_entries(): void
    {
        Ledger::journal('cash.sale')->key('s:1')->debit('1000', 1)->credit('4010', 1)->post();

        $this->expectException(LedgerException::class);

        Ledger::accounts()->update(Ledger::account('4010'), ['type' => 'liability']);
    }

    public function test_renaming_an_account_with_entries_is_allowed(): void
    {
        Ledger::journal('cash.sale')->key('s:1')->debit('1000', 1)->credit('4010', 1)->post();

        $account = Ledger::accounts()->update(Ledger::account('4010'), ['name' => 'Own-store sales']);

        $this->assertSame('Own-store sales', $account->name);
    }

    public function test_accounts_with_entries_or_roles_cannot_be_deleted(): void
    {
        Ledger::journal('cash.sale')->key('s:1')->debit('1000', 1)->credit('4010', 1)->post();

        foreach (['4010', '5910'] as $code) {
            try {
                Ledger::accounts()->delete(Ledger::account($code));
                $this->fail("Account {$code} was deleted.");
            } catch (LedgerException) {
                $this->assertTrue(Account::query()->where('code', $code)->exists());
            }
        }

        Ledger::accounts()->delete(Ledger::account('4110'));
        $this->expectException(AccountNotFoundException::class);
        Ledger::account('4110');
    }
}
