# Integration guide

This is the step-by-step way to connect a Laravel application to the ledger,
whether it's a new project or an old one whose balances live in wallet columns.

```
 Your flows (unchanged maths)          Ledger                       Reads
 ───────────────────────────           ──────                       ─────
 checkout / payment callback ─┐
 order status change ─────────┤                                     wallets (projection)
 refunds / cancellations ─────┼─ Posting rule ─▶ Ledger::post() ─▶  statements
 wallet / loyalty ────────────┤   (one class    validate · lock     P&L · balance sheet
 payouts / cash collection ───┤    per event)    · write · number   reconciliation
 expenses / payroll ──────────┘
```

## Step 1: Install and configure

Follow [Installation](01-installation.md): require the package, publish the config,
register the morph map, run `ledger:install` with the closest chart, and add
`HasLedger` to the party models.

Decide these before writing any posting code:

| Decision | Options | Default |
|---|---|---|
| Base currency | Any ISO code | `USD` |
| Segments | Business units you report separately (retail/wholesale, branches, channels) | none |
| Numbering | `gapless` (audit-friendly, serialises) or `fast` (no lock) | `gapless` |
| Balance tracking | `party` or `all` | `party` |
| DB triggers | On for production if your DB user may create triggers | off |

## Step 2: Map every money flow

Before writing code, list **every place money moves** in the app. Search for
writes to wallet or balance columns, payment callbacks, status transitions and
admin adjustment screens. For each one, fill in one row:

| Flow | Where it happens today | Event | Idempotency key | Source | Lines |
|---|---|---|---|---|---|
| Customer pays by card | `StripeController@success` | `order.paid` | `payment:{payment_request_id}:captured` | order | Dr 1010 · Cr 2050 (customer) |
| Order delivered | Admin, vendor app, courier webhook | `order.delivered` | `order:{id}:delivered` | order | see recipe |
| Refund approved | `RefundController@approve` | `refund.approved` | `refund:{id}:approved` | refund | see recipe |
| Monthly rent | Accountant | `expense.rent` | `bill:{id}` | bill | Dr 5110 · Cr 1101 |

This table is your posting catalogue, and it's what reviewers check. Keep it in the
project, for example in `docs/ledger-postings.md`.

Rules for the catalogue:

- **One event, one key.** If the same fact can be triggered from six places
  (admin, vendor panel, API, webhook...), they all post the same event with the
  same key. The second caller gets the first journal back.
- **Name the fact, not the request**: `order:15:delivered`, never a random UUID.
- **Every party account line names its party**: customer, seller, rider, employee, supplier.
- **Platform income and costs have no party.**
- **Each component gets a tag** (`goods`, `commission`, `tax`, `shipping`, `coupon`)
  so reports can split it later.

[Recipes](06-recipes.md) has ready-made lines for the usual flows.

## Step 3: Write posting rules

One class per event under `app/Ledger/Postings`, with account codes from
`App\Ledger\Accounts` constants:

```
app/Ledger/
├── Accounts.php                    # account code constants
├── Postings/
│   ├── OrderPaidPosting.php
│   ├── OrderDeliveredPosting.php
│   ├── RefundApprovedPosting.php
│   ├── WalletTopUpPosting.php
│   └── PayoutRequestedPosting.php
├── Listeners/
│   └── PostOrderDeliveredJournal.php
└── Reconcilers/
    └── CustomerWalletReconciler.php
```

Guidelines:

- **Reuse stored numbers.** Read `orders.admin_commission`; don't recalculate it.
  If two code paths compute something differently, fix that first.
- **Use `Amount`** (`Amount::of(...)->plus(...)`) for any arithmetic. Never use floats.
- **Set `occurredAt`** to when the business event happened (`delivered_at`),
  not when the code ran, so replays and backfills land in the right period.
- **Set `source`** to the record a person would look up.
- **Keep rules pure.** They read the model and add lines; they don't save
  anything or call other services.

## Step 4: Hook the rules into your flows

### Status changes: in the same transaction, behind a lock

```php
public function markDelivered(Order $order, $actor): void
{
    DB::transaction(function () use ($order, $actor) {
        $order = Order::query()->lockForUpdate()->findOrFail($order->id);

        if ($order->status === 'delivered') {
            return;
        }

        $order->update(['status' => 'delivered', 'delivered_at' => now()]);

        event(new OrderDelivered($order, $actor));   // listener posts the journal
    });
}
```

```php
// Synchronous listener: do NOT implement ShouldQueue.
final class PostOrderDeliveredJournal
{
    public function handle(OrderDelivered $event): void
    {
        Ledger::post(new OrderDeliveredPosting($event->order));
    }
}
```

**Don't queue posting listeners.** A queued listener runs after the transaction, so a
refused posting can't roll back the status change and the books drift. Keep
queues for things that *react* to a posting (notifications, webhooks); those listen
to `JournalPosted`, which fires after commit.

If a flow is reached from several entry points, make all of them fire the same event,
and give each event exactly one posting listener.

### Payment gateway callbacks

The order matters: verify, flip the flag conditionally, then post.

```php
public function callback(Request $request)
{
    $payment = PaymentRequest::findOrFail($request->payment_id);

    // 1. Verify with the gateway (signature or API lookup). Never trust a status in the request.
    $captured = $this->gateway->verify($payment, $request);
    abort_unless($captured, 400);

    DB::transaction(function () use ($payment, $captured) {
        // 2. Conditional update: exactly one request wins.
        $won = PaymentRequest::query()
            ->whereKey($payment->id)->where('is_paid', 0)
            ->update(['is_paid' => 1, 'transaction_id' => $captured->id]);

        // 3. Post either way: the key makes a replay harmless.
        Ledger::post(new PaymentCapturedPosting($payment->fresh(), $captured));

        if ($won) {
            $this->fulfil($payment);   // place the order, credit the wallet...
        }
    });
}
```

### Spending a balance (wallet payments, withdrawals, credit limits)

Read the balance under a lock, in the same transaction that posts against it:

```php
DB::transaction(function () use ($customer, $order) {
    $available = Ledger::lockBalance(Accounts::CUSTOMER_WALLET, $customer);

    if ($available->isLessThan($order->total)) {
        throw new InsufficientWalletBalance;
    }

    Ledger::journal('order.paid')
        ->idempotencyKey("order:{$order->id}:wallet-paid")
        ->source($order)
        ->debit(Accounts::CUSTOMER_WALLET, $order->total, party: $customer)
        ->credit(Accounts::PREPAYMENTS, $order->total, party: $customer)
        ->post();
});
```

Two concurrent checkouts serialise on the balance row, so the second one sees
the reduced balance.

### Cancellations, returns, failed deliveries

- **Undoing a whole journal** (delivered → returned): `Ledger::reverse($journal)`,
  then post what actually happens next (a refund).
- **Partial undo** (partial refund): post a new event with its own key.
- **Cancelling before anything was posted**: post nothing.

Find the journal to reverse with its key: `Ledger::find("order:{$order->id}:delivered")`.

### Admin adjustments and expenses

Give accountants a screen backed by `POST /api/ledger/journals` (origin `manual`),
or your own form that builds a journal. Always require a reason
(`description`) and record `createdBy`.

## Step 5: Read from the ledger

Replace reads of balance columns with ledger balances:

```php
// Before: $seller->wallet->total_earning - $seller->wallet->collected_cash
// After:
$withdrawable = Ledger::balance([Accounts::VENDOR_PAYABLE, Accounts::CASH_HELD_BY_VENDOR], $seller, side: 'credit');
```

| What | Call |
|---|---|
| Customer wallet | `Ledger::balance('2000', $customer)` |
| Vendor withdrawable | `Ledger::balance(['2100', '1100'], $seller, side: 'credit')` |
| Receivable from a customer | `Ledger::balance('1200', $customer)` |
| All payables, per supplier | `Ledger::partyBalances('2000')` |
| A party's statement | `Ledger::statement('2100', $seller, from: ..., to: ...)` |
| Revenue this month, wholesale | `Ledger::incomeStatement(now()->startOfMonth(), now(), 'wholesale')->totalRevenue` |
| P&L | `Ledger::incomeStatement($from, $to, $segment)` |

If mobile apps or old screens read wallet columns directly, keep the columns and
make them a **projection**:

```php
final class ProjectWallets
{
    public function handle(JournalPosted $event): void
    {
        $event->journal->entries
            ->where('party_type', 'customer')
            ->pluck('party_id')->unique()
            ->each(fn ($id) => User::whereKey($id)->update([
                'wallet_balance' => (string) Ledger::balance('2000', ['customer', $id]),
            ]));
    }
}
```

Once the ledger is the source of truth, nothing else may write those columns.

## Step 6: Reports and screens

Everything below returns `Arrayable`/`JsonSerializable` objects you can render in Blade or return as JSON:

- Trial balance, income statement and balance sheet, by date and segment
- General ledger per account, and statements per party
- Sub-ledger listings (every vendor's payable, every customer's wallet)

Turn on the JSON API (`LEDGER_ROUTES=true`) to back an accounting section quickly,
and define the `viewLedger` and `manageLedger` gates. See [JSON API](10-api.md).

## Step 7: Operate it

```php
Schedule::command('ledger:verify')->dailyAt('02:00')->onOneServer();
Schedule::command('ledger:reconcile')->dailyAt('02:30')->onOneServer();   // while migrating
```

Month end: review the trial balance, then `php artisan ledger:close-period 2026-10`.

Alert on a non-zero exit from `ledger:verify`. In a sound ledger it never fails.

## Step 8: Test it

See [Testing](13-testing.md). At minimum:

- One golden-number test per posting rule (fixed inputs, exact lines).
- A replay test per rule: post twice, assert one journal.
- `Ledger::verify()` returns `[]` at the end of every feature test that posts.

## Existing apps: the rollout

For an app that already has wallets and live money, don't switch over in one go.
[Reconciliation and migration](09-reconciliation-and-migration.md) has the
detail. In outline:

1. **Fix the writers.** Make callbacks verify, approvals check pending state,
   status changes lock, and remove duplicate wallet writers.
2. **Install the ledger** with posting disabled, and write the rules and tests.
3. **Post opening balances** per party from the legacy columns, at a cut-over date.
4. **Dual write, one domain at a time**: customer wallet → orders → refunds →
   payouts and cash → the rest. Run a reconciler per domain nightly.
5. **Switch reads** once a domain has reconciled cleanly for about two weeks.
   Checks read `lockBalance()`, and the columns become a projection.
6. **Move reports** onto ledger queries one at a time, checking the numbers match.

## Integration checklist

- [ ] Morph map registered; aliases match the chart's party types
- [ ] `App\Ledger\Accounts` constants; no bare codes in controllers
- [ ] Posting catalogue written down (event, key, source, lines)
- [ ] One posting rule per event; each reads stored numbers
- [ ] Every rule runs synchronously inside the business transaction
- [ ] Status rows locked (`lockForUpdate`) before transitions that post
- [ ] Gateway callbacks verified and flipped with a conditional update
- [ ] Balance checks use `lockBalance()` inside the posting transaction
- [ ] Cancellations and returns reverse or post an undo event
- [ ] No code writes wallet columns except a `JournalPosted` projector
- [ ] Golden-number and replay tests for every rule; `verify()` is empty in tests
- [ ] `ledger:verify` (and `ledger:reconcile` while migrating) scheduled
- [ ] Gates defined before turning on the API
