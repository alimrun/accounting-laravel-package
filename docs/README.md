# Laravel Ledger documentation

A double-entry general ledger for Laravel applications. Start with
[Concepts](02-concepts.md) if you are new to double-entry bookkeeping, or the
[Integration guide](05-integration-guide.md) if you are wiring the ledger into
an existing project.

| # | Page | Read it when |
|---|---|---|
| 1 | [Installation](01-installation.md) | Adding the package to a project |
| 2 | [Concepts](02-concepts.md) | You need the vocabulary: journals, entries, accounts, parties, segments |
| 3 | [Chart of accounts](03-chart-of-accounts.md) | Choosing, customising or extending the accounts |
| 4 | [Posting journals](04-posting.md) | Writing any code that records money |
| 5 | [Integration guide](05-integration-guide.md) | Connecting a whole application, step by step |
| 6 | [Recipes](06-recipes.md) | Looking for the right debits and credits for a common transaction |
| 7 | [Balances and reports](07-balances-and-reports.md) | Reading wallets, statements, P&L, balance sheet |
| 8 | [Periods and closing](08-periods.md) | Month-end close and reopening |
| 9 | [Reconciliation and migration](09-reconciliation-and-migration.md) | Moving an app with wallet columns onto the ledger |
| 10 | [JSON API](10-api.md) | Building an accounting UI |
| 11 | [Configuration](11-configuration.md) | Every config key and what it changes |
| 12 | [Database and performance](12-database-and-performance.md) | Locks, numbering, indexes, scale |
| 13 | [Testing your integration](13-testing.md) | Writing tests for code that posts |
| 14 | [Troubleshooting](14-troubleshooting.md) | An exception you don't understand |

## The short version

```php
use Alimrun\Ledger\Facades\Ledger;

DB::transaction(function () use ($order) {
    $order->update(['status' => 'delivered']);

    Ledger::journal('order.delivered')
        ->idempotencyKey("order:{$order->id}:delivered")
        ->source($order)
        ->debit('1200', $order->total, party: $order->customer)   // receivable
        ->credit('4000', $order->subtotal)                        // sales
        ->credit('2200', $order->tax)                             // tax payable
        ->post();
});

Ledger::balance('1200', $customer);        // what this customer owes
Ledger::incomeStatement('2026-01-01', '2026-12-31');
```

Seven rules hold for every posting, whichever project it runs in:

1. Debits equal credits, or the journal is refused.
2. A journal is never edited or deleted; it is reversed.
3. Every journal has an idempotency key that names the business fact.
4. Post inside the same database transaction as the business change.
5. Accounts are referenced by **string** code (`'4000'`), never by integer.
6. Lines on a party account carry the party; lines on platform accounts don't.
7. Amounts are never negative; put them on the other side.
