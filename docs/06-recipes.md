# Recipes

Ready-made debits and credits for common transactions. **Standard** recipes use the
`standard` chart, and **Marketplace** recipes use the `marketplace` chart. Adjust the
codes to your own chart.

Notation: `Dr 1200 (customer)` means a debit to account 1200 with the customer as
the party.

---

## Standard chart

### Credit sale (invoice)

Sale 1,000 + 15% tax, payable in 30 days.

```php
Ledger::journal('invoice.issued')
    ->idempotencyKey("invoice:{$invoice->id}:issued")
    ->source($invoice)->occurredAt($invoice->issued_at)
    ->debit('1200', '1150.00', party: $invoice->customer)        // receivable
    ->credit('4000', '1000.00', tag: 'goods')                    // sales
    ->credit('2200', '150.00', tag: 'tax')                       // output tax
    ->post();
```

### Customer pays the invoice

```php
Ledger::journal('invoice.paid')
    ->idempotencyKey("receipt:{$receipt->id}")
    ->source($receipt)->paymentMethod('bank_transfer')->transactionRef($receipt->bank_ref)
    ->debit('1101', '1150.00')
    ->credit('1200', '1150.00', party: $invoice->customer, reference: $invoice)
    ->post();
```

A partial payment posts the paid amount only; the receivable keeps the rest.

### Cash sale with a discount

List price 500, 10% discount, cash.

```php
->debit('1000', '450.00')
->debit('4800', '50.00', tag: 'discount')        // contra revenue: shows as negative sales
->credit('4000', '500.00', tag: 'goods')
```

### Card sale through a gateway, then settlement

At sale (gross):

```php
->debit('1050', '200.00', tag: 'stripe')          // gateway clearing
->credit('4000', '200.00')
```

When the gateway pays out net of a 2.9% fee:

```php
Ledger::journal('gateway.settlement')->idempotencyKey("payout:stripe:{$payout->id}")
    ->debit('1101', '194.20')
    ->debit('5400', '5.80', tag: 'gateway_fee')
    ->credit('1050', '200.00')
    ->post();
```

Account 1050 then holds exactly what the gateway still owes you.

### Cost of goods sold

When stock leaves (at cost):

```php
->debit('5000', '320.00', tag: 'cogs', reference: $product)
->credit('1300', '320.00')
```

Post it in the same journal as the sale or as its own `inventory.issued` event.

### Sales return and refund

Customer returns goods worth 1,000 + 150 tax from a paid invoice:

```php
Ledger::journal('sales.return')->idempotencyKey("credit-note:{$note->id}")
    ->debit('4810', '1000.00', tag: 'return')         // sales returns (contra revenue)
    ->debit('2200', '150.00', tag: 'tax')             // tax no longer owed
    ->credit('2600', '1150.00', party: $customer)     // refund owed
    ->post();

// when the money goes out
Ledger::journal('refund.paid')->idempotencyKey("refund:{$refund->id}:paid")
    ->debit('2600', '1150.00', party: $customer)
    ->credit('1101', '1150.00')
    ->post();
```

If the invoice was unpaid, credit `1200 (customer)` instead of 2600.

### Purchase bill from a supplier and payment

```php
Ledger::journal('bill.received')->idempotencyKey("bill:{$bill->id}")
    ->debit('1300', '800.00', tag: 'stock')            // or an expense account
    ->debit('1500', '120.00', tag: 'input_tax')
    ->credit('2000', '920.00', party: $bill->supplier)
    ->post();

Ledger::journal('bill.paid')->idempotencyKey("bill-payment:{$payment->id}")
    ->debit('2000', '920.00', party: $bill->supplier, reference: $bill)
    ->credit('1101', '920.00')
    ->post();
```

### Operating expenses

```php
Ledger::journal('expense.rent')->idempotencyKey("expense:{$expense->id}")
    ->source($expense)->segment('dhaka-branch')->description('October office rent')
    ->debit('5110', '1500.00', tag: 'rent')
    ->credit('1101', '1500.00')
    ->post();
```

Unpaid at month end? Accrue it: `Dr 5110 · Cr 2300`, then pay it: `Dr 2300 · Cr 1101`.

### Payroll

```php
// accrue the month's salary
Ledger::journal('payroll.accrued')->idempotencyKey("salary:{$employee->id}:2026-10")
    ->occurredAt('2026-10-31')
    ->debit('5100', '2500.00', tag: 'salary')
    ->credit('2400', '2500.00', party: $employee)
    ->post();

// pay it, recovering an earlier advance of 300
Ledger::journal('payroll.paid')->idempotencyKey("salary-payment:{$employee->id}:2026-10")
    ->debit('2400', '2500.00', party: $employee)
    ->credit('1410', '300.00', party: $employee, tag: 'advance_recovery')
    ->credit('1101', '2200.00')
    ->post();
```

An advance is `Dr 1410 (employee) · Cr 1000`.

### Customer wallet: top-up, spend, refund to wallet

```php
// top-up 100 with a 5 bonus
->debit('1050', '100.00')
->debit('5200', '5.00', tag: 'topup_bonus')
->credit('2100', '105.00', party: $customer)

// pay an order from the wallet (inside lockBalance(), see Balances)
->debit('2100', '60.00', party: $customer)
->credit('4000', '60.00')

// refund to the wallet
->debit('4810', '60.00')
->credit('2100', '60.00', party: $customer)
```

### Subscriptions paid in advance (deferred revenue)

Annual plan 1,200 paid upfront:

```php
->debit('1050', '1200.00')
->credit('2700', '1200.00', tag: 'subscription')
```

Each month, from a scheduled command:

```php
Ledger::journal('subscription.recognised')
    ->idempotencyKey("subscription:{$sub->id}:recognise:{$period}")
    ->occurredAt($monthEnd)
    ->debit('2700', '100.00')
    ->credit('4030', '100.00')
    ->post();
```

The key makes the command safe to re-run.

### Fixed asset and depreciation

```php
// buy a laptop
->debit('1600', '1800.00', reference: $asset)->credit('1101', '1800.00')

// monthly straight-line depreciation over 36 months
Ledger::journal('asset.depreciation')->idempotencyKey("depreciation:{$asset->id}:{$period}")
    ->debit('5500', '50.00')->credit('1610', '50.00', reference: $asset)
    ->post();
```

### Owner capital, drawings, loans

```php
->debit('1101', '50000.00')->credit('3200', '50000.00')      // capital introduced
->debit('3300', '2000.00')->credit('1101', '2000.00')        // owner withdraws
->debit('1101', '20000.00')->credit('2500', '20000.00')      // loan received
->debit('2500', '1000.00')->debit('5900', '150.00', tag: 'interest')->credit('1101', '1150.00') // repayment
```

### Tax remittance

Pay the net tax for the month: output 2,400, input 900.

```php
->debit('2200', '2400.00')
->credit('1500', '900.00')
->credit('1101', '1500.00')
```

### Bank transfer between your own accounts

Add `1102` under group 1100, then:

```php
->debit('1102', '5000.00')->credit('1101', '5000.00')
```

### Foreign-currency sale

The customer pays 1,000 BDT at 0.0085 USD/BDT, so the base amount is 8.50 USD.

```php
Ledger::journal('cash.sale')->idempotencyKey("sale:{$sale->id}")
    ->currency('BDT', '0.0085')
    ->debit('1000', '8.50')
    ->credit('4000', '8.50')
    ->post();
```

Convert with the rate you stored on the sale, **before** posting.

---

## Marketplace chart

The order in these examples: items 200.00, seller-funded product discount 10.00,
platform coupon 20.00, tax 17.00 remitted by the vendor, seller-wise shipping 15.00,
10% commission on 170.00 = 17.00. The customer paid 202.00.

### `order.paid`: prepaid by gateway or wallet

```php
->debit('1010', '202.00', tag: 'stripe')                 // or Dr 2000 (customer) for a wallet payment
->credit('2050', '202.00', party: $customer)             // prepayment held until delivery
```

Key: `payment:{payment_request_id}:captured`.

### `order.delivered`: prepaid

```php
->debit('2050', '202.00', party: $customer, tag: 'prepayment')
->debit('5000', '20.00', tag: 'coupon')                          // platform-funded coupon
->credit('2100', '205.00', party: $seller, tag: 'goods')         // 170 + 20 − 17 + 17 + 15
->credit('4000', '17.00', tag: 'commission')
```

Totals: 222.00 / 222.00.

### `order.delivered`: cash on delivery, collected by a platform rider

```php
->debit('1110', '202.00', party: $deliveryMan, tag: 'cod')       // cash the rider now holds
->debit('5000', '20.00', tag: 'coupon')
->credit('2100', '205.00', party: $seller, tag: 'goods')
->credit('4000', '17.00', tag: 'commission')
```

If the seller collected it, debit `1100 (seller)` instead. The commission the seller
owes then nets against their payable through
`withdrawable = 2100 − 1100`.

### `order.dm_earning`: the rider's fee

```php
->debit('5200', '3.00', tag: 'delivery_fee')                       // platform rider
// or ->debit('2100', '3.00', party: $seller)                       // vendor's own rider
->credit('2110', '3.00', party: $deliveryMan)
```

### `cash.collected`: admin collects cash from a rider

```php
->debit('1000', '202.00')
->credit('1110', '202.00', party: $deliveryMan)
```

### `refund.approved`: after delivery, with commission given back pro rata

```php
->debit('2100', '188.00', party: $seller, tag: 'goods')     // vendor's share back
->debit('4000', '17.00', tag: 'commission')                 // platform returns commission
->credit('5000', '20.00', tag: 'coupon')                    // coupon cost reversed
->credit('2000', '185.00', party: $customer)                // to wallet (or 2600 if paid out)
```

Work out the split from your refund policy. The journal only has to balance and
put each share on the right party.

### Cancelling a prepaid order before delivery

```php
->debit('2050', '202.00', party: $customer)
->credit('2000', '202.00', party: $customer)                // back to wallet, or 2600
```

### Payouts

```php
// vendor requests 150
Ledger::journal('payout.requested')->idempotencyKey("withdraw:{$w->id}:requested")
    ->debit('2100', '150.00', party: $seller)
    ->credit('2101', '150.00', party: $seller)
    ->post();

// admin approves and pays
Ledger::journal('payout.approved')->idempotencyKey("withdraw:{$w->id}:approved")
    ->debit('2101', '150.00', party: $seller)
    ->credit('1020', '150.00')
    ->post();

// or denies: undo the request
Ledger::reverse(Ledger::find("withdraw:{$w->id}:requested"), 'Payout denied');
```

Delivery men use 2110 and 2111 the same way.

### Wallet top-up with bonus, admin credit, loyalty, referral

```php
->debit('1010', '100.00')->debit('5100', '5.00', tag: 'bonus')->credit('2000', '105.00', party: $customer)  // wallet.topup
->debit('5900', '10.00')->credit('2000', '10.00', party: $customer)                                     // wallet.admin_credit
->debit('5120', '4.00')->credit('2000', '4.00', party: $customer)                                       // loyalty.converted
->debit('5110', '2.00')->credit('2000', '2.00', party: $customer)                                       // referral.earned
```

### POS sale by the platform's own store

```php
->debit('1000', '55.00')->credit('4010', '50.00', tag: 'goods')->credit('2200', '5.00', tag: 'tax')
```

### Auction

```php
->debit('2000', '5.00', party: $customer)->credit('4110', '5.00')                         // auction.entry_fee
->debit('1010', '330.00')->credit('2120', '330.00', party: $owner)                        // auction.claim_paid
->debit('2120', '33.00', party: $owner)->credit('4100', '33.00')                         // commission at delivery
->debit('2120', '297.00', party: $owner)->credit('1020', '297.00')                        // auction.payout
```

### Courier COD remittance

```php
->debit('1020', '485.00')->debit('5210', '15.00', tag: 'courier_fee')->credit('1120', '500.00')
```

---

## Choosing between reverse and a new event

| Situation | Do |
|---|---|
| The journal should never have happened (wrong status, duplicate through a different key) | `Ledger::reverse()` |
| Delivered, then returned in full | `Ledger::reverse()` of `order.delivered`, then the refund event |
| Partial refund, partial return | New event (`refund.approved`) with its own key |
| Payout request denied | `Ledger::reverse()` of `payout.requested` |
| Wrong amount posted | Reverse, then repost with a new key (`...:v2`) |
