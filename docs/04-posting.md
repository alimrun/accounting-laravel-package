# Posting journals

Every write to the ledger goes through `Ledger::post()`, either from the fluent
builder or from a posting-rule class. The poster validates the journal, takes its
locks, writes the journal, entries and balances, assigns the number, and fires
`JournalPosted` after commit.

## The builder

```php
use Alimrun\Ledger\Facades\Ledger;

$journal = Ledger::journal('invoice.issued')          // event name
    ->idempotencyKey("invoice:{$invoice->id}:issued")  // required, unique
    ->source($invoice)                                 // model, Ref or ['invoice', 15]
    ->occurredAt($invoice->issued_at)                  // defaults to now()
    ->segment('wholesale')                             // lines inherit it
    ->currency('EUR', '1.0842')                        // display currency + rate to base
    ->description("Invoice {$invoice->number}")
    ->paymentMethod('bank_transfer')
    ->transactionRef($invoice->number)
    ->createdBy(auth()->user())
    ->meta(['po' => $invoice->po_number])
    ->debit('1200', $invoice->total, party: $invoice->customer)
    ->credit('4000', $invoice->subtotal, tag: 'goods')
    ->credit('2200', $invoice->tax, tag: 'tax')
    ->post();
```

### Line arguments

```php
->debit(
    account: '4000',            // string code, an Account model, or an int id
    amount: '120.50',           // string | int | float | BigDecimal | null
    party: $customer,           // Model | Ref | ['customer', 812] | null
    tag: 'commission',          // short component label
    reference: $orderLine,      // another record this line points to
    memo: 'Line memo',
    segment: 'retail',          // overrides the journal segment for this line
    dimensions: ['zone_id' => 3],
)
```

> **Pass codes as strings.** `'4000'` is account code 4000; `4000` (an int) is
> the account whose database **id** is 4000.

### Amounts

- Rounded half-up to 12 decimal places with exact arithmetic. `0.1 + 0.2` posts as `0.300000000000`;
  a 13th digit rounds (`1.1234567890125` → `1.123456789013`).
- `0` and `null` lines are **skipped**, so a rule can list every component
  (coupon, free delivery, referral) and only the non-zero ones are written.
- Negative amounts throw `InvalidAmountException`. A negative debit is a credit,
  so post it on the other side.
- Strings are best. When you start from a database `DECIMAL`, Laravel already
  gives you a string; don't cast it to float.

Use `Alimrun\Ledger\Support\Amount` for arithmetic in posting rules:

```php
use Alimrun\Ledger\Support\Amount;

$vendorShare = Amount::of($order->goods)
    ->plus($order->coupon)
    ->minus($order->commission)
    ->plus($order->vendor_tax);

Amount::sum([$a, $b, $c]);          // BigDecimal
Amount::format('12.5');             // "12.500000000000"
```

### Conditional lines

The builder is `Conditionable`:

```php
->when($order->tax_payer === 'platform',
    fn ($j) => $j->credit('2200', $order->tax, tag: 'tax'),
    fn ($j) => $j->credit('2100', $order->tax, party: $order->seller, tag: 'tax'),
)
```

## Posting rules (recommended)

When the same event can be reached from several places (admin panel, vendor app,
webhook, queue), put its lines in one class:

```php
namespace App\Ledger\Postings;

use Alimrun\Ledger\Posting\JournalBuilder;
use Alimrun\Ledger\Posting\Posting;
use App\Ledger\Accounts;
use App\Models\Order;

final class OrderDeliveredPosting extends Posting
{
    public function __construct(private readonly Order $order) {}

    public function event(): string
    {
        return 'order.delivered';
    }

    public function idempotencyKey(): string
    {
        return "order:{$this->order->id}:delivered";
    }

    public function build(JournalBuilder $journal): void
    {
        $o = $this->order;

        $journal->source($o)
            ->segment($o->business_module)
            ->occurredAt($o->delivered_at)
            ->debit(Accounts::PREPAYMENTS, $o->paid_amount, party: $o->customer)
            ->debit(Accounts::COUPON_EXPENSE, $o->platform_coupon, tag: 'coupon')
            ->credit(Accounts::VENDOR_PAYABLE, $o->seller_amount, party: $o->seller, tag: 'goods')
            ->credit(Accounts::COMMISSION, $o->admin_commission, tag: 'commission');
    }
}
```

```php
Ledger::post(new OrderDeliveredPosting($order));
```

Posting rules **translate numbers your app already stores**. They don't recompute
commission or tax; read the columns your existing calculation wrote.

## What `post()` checks

In order:

1. The event name isn't empty and there is an idempotency key (unless
   `idempotency.required` is off).
2. Every account exists, isn't a group and is active.
3. Every line satisfies its account's party rule (`strict_parties`).
4. Every line has exactly one positive side.
5. Debits equal credits. A difference within `rounding.tolerance` (0.000010) adds
   one line to the rounding account; anything larger throws `UnbalancedJournalException`.
6. There are at least two lines.
7. If the key already exists: same lines → return the existing journal; different
   lines → `IdempotencyConflictException`.
8. The period of `occurred_at` is open.

Only then does it write, inside a transaction.

## Return values

| Situation | `post()` returns |
|---|---|
| New journal | The `Journal`, with `entries` loaded; `wasRecentlyCreated === true` |
| Key already posted with the same lines | The existing `Journal`; `wasRecentlyCreated === false` |
| Ledger disabled (`LEDGER_ENABLED=false`) | `null` |
| Any validation failure | Throws a `LedgerException` subclass |

## Transactions and atomicity

`post()` runs in a transaction on the ledger connection. If you are already in a
transaction on the **same connection**, it nests as a savepoint, and the journal
commits or rolls back with your business change:

```php
DB::transaction(function () use ($order) {
    $order = Order::query()->lockForUpdate()->findOrFail($order->id); // serialise status changes

    if ($order->status === 'delivered') {
        return;                                // already done
    }

    $order->update(['status' => 'delivered']);
    Ledger::post(new OrderDeliveredPosting($order));  // throws → status change rolls back
});
```

A refused posting inside your transaction rolls back only its own savepoint, so you
can catch it and carry on if that's what you want.

When the ledger opens the outermost transaction itself, it retries deadlocks up to
`posting.attempts` times.

## Reversals and corrections

```php
$reversal = Ledger::reverse($journal, reason: 'Order returned', occurredAt: now(), reversedBy: auth()->user());
```

- Posts the mirror image of every line, with the same parties, tags, segments and references.
- Event `{original}.reversal`, key `reversal:{journal_id}`, `reversal_of_id` set.
- Reversing the same journal again returns the first reversal.
- Allowed even if an account has since been deactivated.
- `occurredAt` defaults to now. If you pass a date in a closed period, it is refused.

To **correct** a journal: reverse it, then post the corrected version with a new
key (for example `order:15:delivered:v2`).

To **partially** undo something (a partial refund), don't reverse. Post a new
journal for the part being undone, such as `refund.approved`. See [Recipes](06-recipes.md).

## Reading what was posted

```php
Ledger::find('order:100245:delivered');                 // by key
Journal::query()->forSource($order)->get();             // everything an order caused
$order->ledgerJournals()->get();                        // same, with HasLedger
Journal::query()->forEvent('payout.approved')->between('2026-10-01', '2026-10-31')->get();
Journal::query()->forParty($seller)->latest('id')->paginate();
Entry::query()->forReference($invoice)->get();
$journal->entries;          // ordered by line_no
$journal->isReversed();     // has a reversal
$journal->reversal;         // the reversing journal
$journal->totalDebit();     // BigDecimal
```

## Kill switch

```env
LEDGER_ENABLED=false
```

or `Ledger::disable()` / `Ledger::enable()` at runtime. While it's disabled, `post()`
and `reverse()` return `null` and write nothing; reads keep working. Use it to
pause a rollout without a deploy, then backfill the gap with the same idempotency
keys once you turn it back on.
