# Reconciliation and migration

## `ledger:verify`: internal consistency

```bash
php artisan ledger:verify      # exit code 1 when anything is wrong
```

```php
$problems = Ledger::verify();  // list<string>; empty when sound
```

It checks that:

- every journal balances,
- every journal has at least two lines,
- every line has exactly one positive side,
- every journal has a number,
- the whole ledger nets to zero,
- every `ledger_balances` row equals the sum of its entries, and every tracked
  (account, party) has a row.

In a sound ledger this never fails. If it does, stop posting, find the cause, then:

```bash
php artisan ledger:rebuild-balances --force   # recompute balance rows from entries
```

Pause posting first (`LEDGER_ENABLED=false`), because the rebuild replaces the table.

## Reconcilers: the ledger against something else

A reconciler compares a balance kept elsewhere (wallet columns, a bank statement, a
payment provider's balance) with the ledger.

```php
namespace App\Ledger\Reconcilers;

use Alimrun\Ledger\Contracts\Reconciler;
use Alimrun\Ledger\Enums\NormalSide;
use Alimrun\Ledger\Support\Amount;
use Alimrun\Ledger\Support\Expectation;
use App\Models\Seller;
use App\Models\User;

final class CustomerWalletReconciler implements Reconciler
{
    public function name(): string
    {
        return 'customer-wallet';
    }

    public function expectations(): iterable
    {
        foreach (User::query()->where('wallet_balance', '!=', 0)->lazyById(1000) as $user) {
            yield new Expectation(
                accounts: '2000',
                party: $user,
                expected: $user->wallet_balance,
                subject: 'users.wallet_balance',
            );
        }
    }
}

final class VendorWithdrawableReconciler implements Reconciler
{
    public function name(): string
    {
        return 'vendor-withdrawable';
    }

    public function expectations(): iterable
    {
        foreach (Seller::query()->with('wallet')->lazyById(500) as $seller) {
            yield new Expectation(
                accounts: ['2100', '1100'],
                party: $seller,
                expected: Amount::of($seller->wallet->total_earning)->minus($seller->wallet->collected_cash),
                subject: 'seller_wallets.withdrawable',
                side: NormalSide::Credit,
            );
        }
    }
}
```

```php
// config/ledger.php
'reconcilers' => [
    App\Ledger\Reconcilers\CustomerWalletReconciler::class,
    App\Ledger\Reconcilers\VendorWithdrawableReconciler::class,
],
```

```bash
php artisan ledger:reconcile   # table of checked/differences; exit 1 when any differ
```

Every difference larger than `rounding.tolerance` is stored in
`ledger_reconciliation_issues` (reconciler, subject, accounts, party, expected,
actual, difference) and fires `ReconciliationIssueDetected`. The next run updates the
same open issue, and when the numbers agree again it resolves the issue automatically.

```php
ReconciliationIssue::query()->unresolved()->get();
$issue->resolve('Legacy column double-counted refund #881');
```

Remember to also yield parties whose legacy value is zero but who might have a ledger
balance, or the reconciler will miss them.

## Migrating an app that keeps balances in columns

Use this sequence. It keeps the old system live until the ledger has proven itself.

### Phase 0: make the writers safe

The ledger can't fix inputs that are wrong. Before dual writing:

- Payment callbacks verify with the gateway, and the success handler runs only after a
  conditional `is_paid 0 → 1` update.
- Approvals (withdrawals, refunds, claims) check the pending state with a conditional update.
- Every delivered/completed path locks the order row and fires one event.
- One writer per wallet: remove duplicate helpers that each update the same column.
- Unique indexes where one row per entity is assumed.

### Phase 1: install

Install the package, seed the chart, and write the posting rules and their tests, with
`LEDGER_ENABLED=false` in production.

### Phase 2: opening balances

At a cut-over moment, post each party's legacy balance against opening balance equity:

```php
User::query()->where('wallet_balance', '!=', 0)->lazyById()->each(
    fn (User $u) => Ledger::openingBalance('2000', $u->wallet_balance, $u, '2026-11-01 00:00:00'),
);
```

`openingBalance()` takes the amount on the account's normal side (negative flips it),
uses the key `opening:{code}:{party}` so it's safe to re-run, and posts with origin `opening`.

Optionally replay recent history with origin `backfill` (`->origin('backfill')`) for
reporting, dated before the cut-over and keyed exactly as live postings would be.

### Phase 3: dual write, one domain at a time

Turn posting on and enable domains in this order: customer wallet → orders → refunds
→ payouts and cash → POS → the rest. Each domain gets a reconciler that runs nightly.
Before moving on, the domain should show no unexplained differences for about 14
consecutive days.

### Phase 4: the ledger becomes the source of truth

- Balance checks (wallet payment, withdrawal limits, cash limits) read
  `Ledger::lockBalance()` inside the posting transaction.
- Wallet columns become a projection, written only by a `JournalPosted` listener.
- A test fails the build if any other code writes those columns.

### Phase 5: reports

Move earning, transaction, expense and tax reports onto ledger queries one at a time,
comparing the numbers with the old report before switching. Write a changelog entry for
every number that changes because a bug was fixed.
