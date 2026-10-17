# Testing your integration

## Setup

The package works with `RefreshDatabase` on SQLite or MySQL. Seed the chart once per test
database:

```php
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function afterRefreshingDatabase(): void
    {
        Ledger::accounts()->flush();
        Ledger::accounts()->seed('marketplace');      // or your chart file
    }
}
```

Turn the triggers on in tests too, so a raw `UPDATE` in your code fails loudly:

```xml
<!-- phpunit.xml -->
<env name="LEDGER_IMMUTABLE_TRIGGERS" value="true"/>
```

## Golden-number tests: one per posting rule

Fixed inputs and exact expected lines. This test documents the accounting and catches
any drift in the numbers your app stores.

```php
public function test_prepaid_order_delivered_posts_the_agreed_lines(): void
{
    $order = Order::factory()->prepaid()->create([
        'order_amount' => '202.00', 'admin_commission' => '17.00', 'coupon_bearer' => 'inhouse',
        'coupon_discount' => '20.00', 'seller_amount' => '205.00',
    ]);

    $journal = Ledger::post(new OrderDeliveredPosting($order));

    $this->assertSame([
        ['2050', '202.000000', '0.000000'],
        ['5000', '20.000000', '0.000000'],
        ['2100', '0.000000', '205.000000'],
        ['4000', '0.000000', '17.000000'],
    ], $journal->entries->map(fn ($e) => [$e->account->code, $e->debit, $e->credit])->all());
}
```

## Replay and concurrency tests

```php
public function test_a_replayed_gateway_callback_posts_once(): void
{
    $this->post('/payment/callback', $payload)->assertOk();
    $this->post('/payment/callback', $payload)->assertOk();

    $this->assertSame(1, Journal::query()->forEvent('order.paid')->count());
    $this->assertEquals('202.000000', (string) Ledger::balance('2050', $customer));
}

public function test_delivering_twice_settles_once(): void
{
    $this->actingAs($admin)->post("/orders/{$order->id}/deliver");
    $this->actingAs($admin)->post("/orders/{$order->id}/deliver");

    $this->assertSame(1, $order->ledgerJournals()->where('event', 'order.delivered')->count());
}
```

## Assert the books are sound after every test that posts

```php
protected function tearDown(): void
{
    if ($this->app && Schema::hasTable('ledger_journals')) {
        $this->assertSame([], Ledger::verify(), 'The ledger is inconsistent');
    }

    parent::tearDown();
}
```

## Full lifecycle test

Run one order through the real services (checkout → pay → deliver → refund → payout)
and then check every party's balance and the trial balance:

```php
$this->assertTrue(Ledger::trialBalance()->isBalanced());
$this->assertEquals('0.000000', (string) Ledger::balance('2050'));   // no prepayments left
$this->assertEquals('188.000000', (string) Ledger::balance('2100', $seller));
```

## Faking events

Ledger events resolve the dispatcher at dispatch time, so `Event::fake()` works even
after the ledger has been used:

```php
Event::fake([JournalPosted::class]);
// ...
Event::assertDispatched(JournalPosted::class, fn ($e) => $e->journal->event === 'order.delivered');
```

Faking `JournalPosted` also stops your projector listeners, so don't fake it in tests that
read projected wallet columns.

## Disabling the ledger in unrelated tests

```php
Ledger::disable();   // post() returns null; reads still work
```

Prefer leaving it on. Most integration bugs show up as a refused posting.

## Guarding projections

Once wallet columns are projections, add a test that fails when other code writes them:

```php
public function test_only_the_projector_writes_wallet_columns(): void
{
    $offenders = collect(File::allFiles(app_path()))
        ->reject(fn ($f) => str_contains($f->getPathname(), 'Ledger/Listeners/ProjectWallets'))
        ->filter(fn ($f) => preg_match("/['\"]wallet_balance['\"]\s*=>|->wallet_balance\s*[+\-]?=/", $f->getContents()));

    $this->assertEmpty($offenders->map->getRelativePathname()->all());
}
```
