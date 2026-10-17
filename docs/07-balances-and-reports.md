# Balances and reports

All amounts come back as `Brick\Math\BigDecimal` (balances) or as strings with 12
decimals (reports). Compare with `isLessThan()`, `isZero()`, `isGreaterThan()` and so on, and
cast with `(string)` to display. Don't convert to float.

## Balances

```php
Ledger::balance(
    accounts: '2000',                 // code, Account, or a list of them
    party: $customer,                 // Model | Ref | ['customer', 812] | null
    asOf: '2026-09-30 23:59:59',      // null = now
    segment: 'retail',                // null = all
    side: 'credit',                   // NormalSide|string|null; default = first account's normal side
);
```

| Example | Meaning |
|---|---|
| `Ledger::balance('2000', $customer)` | That customer's wallet |
| `Ledger::balance('2000')` | All customer wallets together |
| `Ledger::balance('4000', segment: 'wholesale')` | Wholesale commission, all time |
| `Ledger::balance('5400')` | A group account: all operating expenses |
| `Ledger::balance(['2100', '1100'], $seller, side: 'credit')` | Payable to the seller minus the cash they hold |
| `$customer->ledgerBalance('2000')` | Same, through `HasLedger` |

The result is positive on the requested side. With several accounts, each line counts
toward that side: a credit adds and a debit subtracts when `side` is credit.

### Where balances come from

- **No `asOf` and no `segment`**, and the lines are tracked (they have a party, or
  `balances.track = all`): read from `ledger_balances`, which is O(1).
- Otherwise: aggregated from `ledger_entries` using the indexes.

### Locked balances

```php
DB::transaction(function () {
    $available = Ledger::lockBalance('2000', $customer);   // SELECT ... FOR UPDATE
    // check, then post in this same transaction
});
```

`lockBalance()` needs a tracked row: a party, or `balances.track = all`. It throws
`LedgerException` otherwise.

## Trial balance

```php
$tb = Ledger::trialBalance(asOf: '2026-09-30 23:59:59', segment: null, from: null, includeZero: false);

$tb->rows;          // [['account_id', 'code', 'name', 'type', 'debit', 'credit', 'balance_debit', 'balance_credit'], ...]
$tb->totalDebit;    // sum of balance_debit
$tb->totalCredit;   // sum of balance_credit
$tb->isBalanced();  // always true unless the books are corrupt
```

`debit` and `credit` are activity totals, and `balance_debit` and `balance_credit` show the net on
its natural side. Pass `from` to see activity for a range instead of a cumulative balance.

```bash
php artisan ledger:trial-balance --as-of=2026-09-30 --segment=retail
```

## Income statement (P&L)

```php
$pl = Ledger::incomeStatement(from: '2026-01-01', to: '2026-09-30 23:59:59', segment: 'wholesale');

$pl->revenue;        // [['code', 'name', 'parent_code', 'amount'], ...]
$pl->expenses;
$pl->totalRevenue;
$pl->totalExpenses;
$pl->netIncome;
```

- Closing journals are left out, so a closed month still shows its result.
- Amounts are signed per section: contra-revenue accounts (discounts,
  returns) show negative under revenue.
- `parent_code` lets you group rows under their group account.

## Balance sheet

```php
$bs = Ledger::balanceSheet(asOf: '2026-09-30 23:59:59', segment: null);

$bs->assets; $bs->liabilities; $bs->equity;
$bs->currentEarnings;   // revenue − expenses not yet closed to retained earnings
$bs->totalAssets; $bs->totalLiabilities; $bs->totalEquity;   // equity includes current earnings
$bs->isBalanced();      // assets = liabilities + equity
```

## Statements (general ledger)

```php
$st = Ledger::statement(
    accounts: '2100',            // or ['2100', '1100']
    party: $seller,              // null = every party / no party
    from: '2026-10-01',
    to: '2026-10-31 23:59:59',
    segment: null,
    side: null,                  // default: first account's normal side
    limit: null,                 // cap the lines for very large accounts
);

$st->opening;     // balance before `from`
$st->lines;       // each: entry_id, date, journal_id, journal_number, event, description, account, party, tag, debit, credit, balance (running)
$st->totalDebit; $st->totalCredit;
$st->closing;
```

Use it for vendor, customer, rider, employee and supplier statements, and for the
general ledger of one account (`party: null`). On an account with millions of lines,
always pass a date range or a `limit`.

## Sub-ledger listing

```php
$list = Ledger::partyBalances('2100', asOf: null, includeZero: false);

$list->rows;    // [['party_type', 'party_id', 'debit', 'credit', 'balance'], ...] sorted by balance, largest first
$list->total;   // equals Ledger::balance('2100')
```

This shows what you owe each vendor, what each customer holds in their wallet, and
receivables per customer.

## Querying the models directly

For dashboards and custom reports, query the models. Every common filter has an index.

```php
use Alimrun\Ledger\Models\Entry;
use Alimrun\Ledger\Models\Journal;

// Commission per day this month, retail only
Entry::query()->toBase()
    ->where('account_id', Ledger::account('4000')->id)
    ->where('segment', 'retail')
    ->whereBetween('occurred_at', [now()->startOfMonth(), now()])
    ->selectRaw('DATE(occurred_at) as day, SUM(credit) - SUM(debit) as amount')
    ->groupBy('day')->get();

// Expenses by tag
Entry::query()->toBase()
    ->whereIn('account_id', Ledger::accounts()->descendantIds(Ledger::account('5400')))
    ->selectRaw('tag, SUM(debit) - SUM(credit) as amount')
    ->groupBy('tag')->get();

// Everything that happened to an order
Journal::query()->forSource($order)->with('entries.account')->get();
```

Wrap aggregate results with `Amount::of()` before doing arithmetic. Some drivers,
SQLite in particular, return `SUM()` as a float.

## Reports in controllers

Every report is `Arrayable` and `JsonSerializable`:

```php
return response()->json(Ledger::incomeStatement($request->from, $request->to));
return view('accounting.trial-balance', ['tb' => Ledger::trialBalance($request->as_of)]);
```
