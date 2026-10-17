# Laravel Ledger

A double-entry general ledger for Laravel. Every money movement in your
application (orders, refunds, wallets, payouts, cash collection, expenses,
salaries) is posted as one balanced, append-only journal. Wallets, statements,
profit and loss and the balance sheet are then read from the same books.

- **Double entry**: every journal has two or more lines and debits equal credits to 12 decimal places. Anything else is refused.
- **Append-only**: journals and entries are never edited or deleted. A correction is a reversing journal plus a new one. Optional database triggers block raw `UPDATE`/`DELETE` too.
- **Idempotent**: each journal carries a unique key such as `order:100245:delivered`. A replayed callback gets the existing journal back, and reusing a key for different money throws.
- **Atomic with your business change**: post inside your status-change transaction. If the posting is refused, the status change rolls back with it.
- **Control accounts and sub-ledgers**: one account per concept (`Payable to vendors`), with the vendor, customer or employee stored as the *party* on each line.
- **Dimensions on every line**: segment (business unit, module, branch), tag, reference, free-form dimensions.
- **Exact arithmetic**: `DECIMAL(30,12)` (12 decimal places) in one base currency, computed with `brick/math`. A residual up to 0.000010 goes to the rounding account; a larger one throws.
- **Locked running balances** per party, taken in sorted order to avoid deadlocks, plus `lockBalance()` for wallet payments and limit checks.
- **Periods**: monthly close into retained earnings, refusal of postings into closed months, reopen.
- **Reports**: trial balance, income statement, balance sheet, account and party statements, sub-ledger listings, all by segment.
- **Integrity and reconciliation**: `ledger:verify` proves the books balance; reconcilers compare legacy wallet columns or bank balances with the ledger during a migration.
- Optional **JSON API** for an accounting UI (journals, manual entries, reversals, reports, periods).

Requires PHP 8.2+ and Laravel 11, 12 or 13. Tested on SQLite; designed for MySQL 8 and PostgreSQL.

**Documentation:** [docs/](docs/README.md): installation, concepts, chart of accounts,
posting, a step-by-step [integration guide](docs/05-integration-guide.md),
[recipes](docs/06-recipes.md), reports, periods, migration, API, configuration,
performance, testing and troubleshooting.

**Using Claude Code in a project that uses this package?** Run
`php artisan vendor:publish --tag=ledger-claude` and add `@.claude/ledger.md` to that
project's `CLAUDE.md`, so the integration rules load automatically.

---

## Installation

The package is not on Packagist. Pull it into each project from a path or a private Git repository.

```jsonc
// composer.json of your application
"repositories": [
    { "type": "path", "url": "../accounting-laravel-package" }
    // or: { "type": "vcs", "url": "git@github.com:you/laravel-ledger.git" }
],
```

```bash
composer require alimrun/laravel-ledger:@dev
php artisan vendor:publish --tag=ledger-config      # optional
php artisan ledger:install --chart=standard          # or --chart=marketplace
```

`ledger:install` runs the migrations and seeds the chart of accounts. The migrations create seven tables prefixed `ledger_`: `accounts`, `journals`, `entries`, `balances`, `periods`, `sequences` and `reconciliation_issues`.

Register a morph map so party types stay short and stable:

```php
// AppServiceProvider::boot()
Relation::enforceMorphMap([
    'customer'     => \App\Models\User::class,
    'seller'       => \App\Models\Seller::class,
    'delivery_man' => \App\Models\DeliveryMan::class,
    'order'        => \App\Models\Order::class,
]);
```

Add `Alimrun\Ledger\Concerns\HasLedger` to the models that act as parties or sources.

---

## Chart of accounts

Two presets ship in [resources/charts](resources/charts):

| Preset | For |
|---|---|
| `standard` | Trading and service businesses: cash, bank, receivables, payables, tax, equity, sales, cost of goods sold and operating expenses |
| `marketplace` | Multi-vendor commerce: gateway clearing, cash held by vendors and delivery men, customer wallets and prepayments, payables to vendors and delivery men, commission, in-house sales and shipping revenue, platform-funded discounts and operating expenses |

Each account has a `code`, a `type` (asset, liability, equity, revenue, expense), a normal side (taken from the type, overridable for contra accounts), an optional parent group, and its **party rule**:

- no `party`: lines on this account must not carry a party (platform accounts)
- `'party' => ['seller']`: every line needs a seller party
- `'party' => '*'`: every line needs a party of any type

Three roles are required: `rounding`, `opening_balance_equity` and `retained_earnings`. Both presets set them.

```php
Ledger::accounts()->create([
    'code' => '5407', 'name' => 'Training', 'type' => 'expense', 'parent' => '5400',
]);
Ledger::accounts()->seed(base_path('ledger/charts/my-company.php')); // your own chart
Ledger::accounts()->deactivate(Ledger::account('5407'));
```

Accounts that have entries can be renamed, but their type and normal side cannot change. They cannot be deleted either; deactivate them instead.

---

## Posting

### Fluent builder

```php
use Alimrun\Ledger\Facades\Ledger;

Ledger::journal('order.delivered')
    ->idempotencyKey("order:{$order->id}:delivered")
    ->source($order)                         // the record that caused it
    ->segment('retail')                      // lines inherit it
    ->occurredAt($order->delivered_at)
    ->currency($order->currency, $order->exchange_rate) // display currency; lines stay in base
    ->paymentMethod($order->payment_method)
    ->transactionRef($order->transaction_ref)
    ->createdBy(auth()->user())
    ->debit('2050', $order->paid_amount, party: $order->customer, tag: 'prepayment')
    ->debit('5000', $order->platform_coupon, tag: 'coupon')     // zero amounts are skipped
    ->credit('2100', $vendorShare, party: $order->seller, tag: 'goods', reference: $order)
    ->credit('4000', $order->admin_commission, tag: 'commission')
    ->when($order->tax_payer === 'platform', fn ($j) => $j->credit('2200', $order->tax, tag: 'tax'))
    ->post();
```

`post()` returns the `Journal` with its `entries`, numbered `JV-2026-00000001`.

Amounts can be strings, ints, floats or `BigDecimal`. They are rounded half-up to 12 decimal places. Negative amounts are refused: post them on the other side.

### Posting rules

For anything reached from more than one place, put the lines in a posting class and call it from a listener:

```php
use Alimrun\Ledger\Posting\JournalBuilder;
use Alimrun\Ledger\Posting\Posting;

class OrderDeliveredPosting extends Posting
{
    public function __construct(private Order $order) {}

    public function event(): string { return 'order.delivered'; }

    public function idempotencyKey(): string { return "order:{$this->order->id}:delivered"; }

    public function build(JournalBuilder $journal): void
    {
        $journal->source($this->order)->segment($this->order->business_module)
            ->debit('2050', $this->order->order_amount, party: $this->order->customer)
            ->credit('2100', $this->order->seller_amount, party: $this->order->seller)
            ->credit('4000', $this->order->admin_commission);
    }
}

// A synchronous listener, so it runs inside the status transaction:
class PostOrderDeliveredJournal
{
    public function handle(OrderDelivered $event): void
    {
        Ledger::post(new OrderDeliveredPosting($event->order));
    }
}

DB::transaction(function () use ($order) {
    $order->update(['status' => 'delivered']);
    event(new OrderDelivered($order));   // a refused posting rolls the status back
});
```

### Keys

Make the key describe the business fact once, so it naturally deduplicates:

| Event | Key |
|---|---|
| Gateway captured a payment | `payment:{payment_request_id}:captured` |
| Order delivered | `order:{id}:delivered` |
| Refund approved | `refund:{id}:approved` |
| Wallet top-up | `payment:{payment_request_id}:topup` |
| Payout requested / approved | `withdraw:{id}:requested`, `withdraw:{id}:approved` |
| Monthly salary | `salary:{employee_id}:2026-10` |

### What gets refused

| Problem | Exception |
|---|---|
| Debits ≠ credits beyond the tolerance | `UnbalancedJournalException` |
| Fewer than two lines, group or inactive account, missing key | `InvalidJournalException` |
| Party missing, not allowed, or the wrong type | `PartyMismatchException` |
| Unknown account code | `AccountNotFoundException` |
| Posting into a closed month | `PeriodClosedException` |
| Same key, different lines | `IdempotencyConflictException` |
| Editing or deleting a journal or entry | `ImmutableRecordException` |

All of them extend `Alimrun\Ledger\Exceptions\LedgerException`.

### Corrections

```php
Ledger::reverse($journal, 'Delivered by mistake');      // mirror journal, linked by reversal_of_id
Ledger::post(new OrderDeliveredPosting($order, ...));   // then post the right one with a new key
```

A journal can be reversed once; reversing it again returns the first reversal.

### Kill switch

`LEDGER_ENABLED=false` (or `Ledger::disable()`) makes `post()` return `null` without writing, so a rollout can be paused without a deploy.

---

## Marketplace posting catalogue

The `marketplace` chart comes from a plan for a multi-vendor platform with Retail, Wholesale, POS, wallets, loyalty, delivery men, auctions and couriers. Its posting rules map onto that chart like this:

| Event | Lines |
|---|---|
| `order.paid` | Dr 1010 gateway (or Dr 2000 wallet, customer) · Cr 2050 prepayments (customer) |
| `order.delivered` | Dr 2050 (customer) for prepaid, or Dr 1110 (delivery man) / 1100 (seller) for COD by collector · Dr 5000/5010/5020 platform-funded discounts · Cr 2100 (seller) goods − commission + vendor tax + seller shipping · Cr 4000 commission · Cr 4010 in-house goods · Cr 4020 platform shipping · Cr 2200 platform tax |
| `order.dm_earning` | Dr 5200 (or Dr 2100 seller for a vendor's own rider) · Cr 2110 (delivery man) |
| `order.cancelled_paid` | Dr 2050 (customer) · Cr 2000 (customer) or 2600 |
| `refund.approved` | Dr 2100 (seller) vendor share · Dr 4000 commission share · Dr 5000 platform share · Cr 2000 (customer) or 2600 |
| `wallet.topup` | Dr 1010 amount · Dr 5100 bonus · Cr 2000 (customer) amount + bonus |
| `wallet.admin_credit` | Dr 5900 · Cr 2000 (customer) |
| `loyalty.converted` | Dr 5120 (or 2500) · Cr 2000 (customer) |
| `payout.requested` | Dr 2100 · Cr 2101 (seller), or Dr 2110 · Cr 2111 (delivery man) |
| `payout.approved` | Dr 2101 or 2111 · Cr 1020 bank |
| `payout.denied` | `Ledger::reverse()` of the request |
| `cash.collected` | Dr 1000 (admin) or 1100 (seller) · Cr 1110 (delivery man) |
| `pos.sale` | Dr 1000 / 1100 (seller) / 2000 (customer) · Cr 4010, or Cr 2100 (seller) and 4000 |
| `auction.entry_fee` | Dr 2000 (customer) or 1010 · Cr 4110 |
| `expense.*` | Dr 54xx operating expense · Cr 1020 bank or 1000 cash |

Wallets then become simple ledger reads:

```php
$customerWallet  = Ledger::balance('2000', $customer);
$vendorWithdraw  = Ledger::balance(['2100', '1100'], $seller, side: 'credit'); // payable − cash held
$riderWithdraw   = Ledger::balance(['2110', '1110'], $deliveryMan, side: 'credit');
```

If you keep the old wallet columns for apps that read them, refresh them from a `JournalPosted` listener, which fires after commit.

---

## Recording operating expenses

Salaries, rent, hosting and support are ordinary journals. Tag the line and set the segment, and the income statement picks them up:

```php
Ledger::journal('expense.hosting')
    ->idempotencyKey("bill:{$bill->id}")
    ->source($bill)
    ->segment('platform')
    ->description('AWS October')
    ->debit('5402', $bill->amount, tag: 'infrastructure', dimensions: ['zone_id' => 3])
    ->credit('1020', $bill->amount)
    ->post();
```

Accountants can enter the same thing through `POST /api/ledger/journals` (origin `manual`).

---

## Balances

```php
Ledger::balance('2000', $customer);                         // positive on the account's normal side
Ledger::balance('4000', segment: 'wholesale');              // by segment
Ledger::balance('1010', asOf: '2026-09-30 23:59:59');       // historical
Ledger::balance('5400');                                    // a group includes its children
$customer->ledgerBalance('2000');                           // HasLedger trait

// Pay from a wallet without double spending:
DB::transaction(function () use ($customer, $order) {
    if (Ledger::lockBalance('2000', $customer)->isLessThan($order->total)) {
        throw new InsufficientFunds;
    }

    Ledger::journal('order.paid')->idempotencyKey("order:{$order->id}:paid")
        ->debit('2000', $order->total, party: $customer)
        ->credit('2050', $order->total, party: $customer)
        ->post();
});
```

Balances are `Brick\Math\BigDecimal`. Use `(string)` for display and `->isLessThan()` and similar methods for comparisons.

With the default `balances.track = party`, running totals are kept only for lines that carry a party. Platform totals come from indexed aggregates, which avoids one locked hot row per revenue account. Set it to `all` if you need `lockBalance()` on accounts without a party.

---

## Periods

```bash
php artisan ledger:close-period 2026-09   # closes revenue/expense into retained earnings
php artisan ledger:reopen-period 2026-09  # reverses the closing journal
```

Closing posts one `period.closing` journal, origin `closing`, per segment and party, then refuses any posting dated in that month. The income statement leaves closing journals out, so a closed month still shows what it earned. The balance sheet shows unclosed profit as *current earnings*, so it balances at any date.

---

## Reports

```php
Ledger::trialBalance(asOf: '2026-09-30', segment: 'retail');
Ledger::incomeStatement('2026-01-01', '2026-09-30', segment: 'wholesale');
Ledger::balanceSheet('2026-09-30');
Ledger::statement('2100', $seller, from: '2026-09-01', to: '2026-09-30');       // vendor statement
Ledger::statement(['2100', '1100'], $seller, side: 'credit');                   // withdrawable view
Ledger::partyBalances('2100');                                                  // every vendor's payable
```

Every report implements `Arrayable` and `JsonSerializable`, so you can return it from a controller. Amounts are strings with 12 decimals.

---

## Integrity, reconciliation and migrating an existing app

```bash
php artisan ledger:verify            # every journal balances, balances match entries, TB nets to zero
php artisan ledger:trial-balance
php artisan ledger:reconcile         # runs config('ledger.reconcilers')
php artisan ledger:rebuild-balances  # recompute running totals from entries (pause posting first)
```

```php
// routes/console.php
Schedule::command('ledger:verify')->dailyAt('02:00');
Schedule::command('ledger:reconcile')->dailyAt('02:30');
```

To move an app that keeps balances in wallet columns onto the ledger:

1. **Close the holes first.** Make every writer idempotent and locked, for example with conditional `is_paid 0 → 1` updates and pending-state guards on approvals.
2. **Post opening balances** for each party from the legacy columns: `Ledger::openingBalance('2000', $user->wallet_balance, $user, '2026-11-01')`. They post against opening balance equity, with origin `opening`.
3. **Dual write one domain at a time**, keeping the old columns live. Write a reconciler for each one:

   ```php
   class CustomerWalletReconciler implements \Alimrun\Ledger\Contracts\Reconciler
   {
       public function name(): string { return 'customer-wallet'; }

       public function expectations(): iterable
       {
           foreach (User::query()->lazyById() as $user) {
               yield new Expectation('2000', $user, $user->wallet_balance, 'users.wallet_balance');
           }
       }
   }
   ```

   Differences land in `ledger_reconciliation_issues` and resolve themselves when the numbers agree again.
4. **Make the ledger the source of truth** once it has run clean for a couple of weeks. Wallet checks then read `lockBalance()`, and the old columns become a projection written from `JournalPosted`.

---

## Events

| Event | When |
|---|---|
| `JournalPosted` | after commit, for every new journal |
| `JournalReversed` | after commit, with the original and the reversal |
| `PeriodClosed` / `PeriodReopened` | after commit |
| `ReconciliationIssueDetected` | for each difference a reconciler finds |

Use them for wallet projections, ERP webhooks and cache busting.

---

## JSON API

Set `LEDGER_ROUTES=true`. The routes live under `config('ledger.routes.prefix')` (default `api/ledger`) with the configured middleware. Reads need the `viewLedger` gate and writes need `manageLedger`. If your app doesn't define these gates, they are allowed only in the `local` environment.

```
GET  accounts?tree=1                 POST accounts            PATCH accounts/{code}
GET  accounts/{code}                 POST accounts/{code}/deactivate | activate
GET  journals?event=&account=&party_type=&party_id=&source_type=&source_id=&from=&to=&segment=
GET  journals/{id}                   POST journals            POST journals/{id}/reverse
GET  reports/trial-balance?as_of=&segment=
GET  reports/income-statement?from=&to=&segment=
GET  reports/balance-sheet?as_of=&segment=
GET  reports/statement?accounts[]=2100&party_type=seller&party_id=44&from=&to=&side=
GET  reports/party-balances/{code}
GET  periods                         POST periods/{YYYY-MM}/close | reopen
GET  reconciliation-issues
```

Manual journal body:

```json
{
  "idempotency_key": "rent-2026-10",
  "description": "October rent",
  "occurred_at": "2026-10-01",
  "lines": [
    { "account": "5405", "debit": "1200.50", "memo": "Office" },
    { "account": "1020", "credit": "1200.50" }
  ]
}
```

---

## Database notes

- **Atomicity** needs the ledger on the same connection your app writes with; leave `ledger.database.connection` null. Inside an open transaction a posting nests as a savepoint.
- **Lock order**: the period row (shared), then balance rows sorted by account and party, then the journal sequence last. Keep business transactions that post short.
- **Numbering**: `gapless` (default) uses a locked counter per year, so numbers never skip, but concurrent postings serialise on it until commit. For high-volume apps use `fast`, which numbers from the journal id without a lock.
- **Immutable triggers** (`LEDGER_IMMUTABLE_TRIGGERS=true`) are created by a migration. With binary logging, MySQL needs `SUPER` or `log_bin_trust_function_creators=1`. Enable this before migrating.
- **CHECK constraint**: on MySQL 8.0.16+ and PostgreSQL, every entry must have exactly one positive side.
- **Growth**: expect about five lines per order. The indexes cover account/date, party, reference and segment. Partition `ledger_entries` by year once it reaches tens of millions of rows.

---

## Testing

```bash
composer install
vendor/bin/phpunit
```

The suite covers the invariants (balanced, at least two lines, no zero lines, trial balance nets to zero, balance rows equal their entries), idempotent replay and key conflicts, rollback with the business transaction, gapless numbering, immutability through both Eloquent and the database triggers, reversals, period close and reopen, every report, reconciliation, the commands and the API.
