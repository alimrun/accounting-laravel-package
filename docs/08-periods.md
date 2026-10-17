# Periods and closing

Every journal belongs to the month of its `occurred_at`, written `YYYY-MM`. A period
is open until you close it.

## Closing a month

```bash
php artisan ledger:close-period 2026-09
```

```php
$closingJournal = Ledger::closePeriod('2026-09', closedBy: auth()->user());
```

What happens, in one transaction:

1. The period row is locked exclusively, so postings in flight for that month finish first.
2. For every revenue and expense account with activity in the month (per segment and party),
   the net is moved to retained earnings by a `period.closing` journal:
   origin `closing`, dated the last second of the month, key
   `period:2026-09:closing:{n}`.
3. The period is marked closed. From then on, any journal dated in it throws
   `PeriodClosedException`, including reversals.
4. `PeriodClosed` fires after commit.

A month with no income or expense activity closes without a journal (it returns `null`).

### After closing

- `incomeStatement()` still reports the month, because closing journals are excluded.
- `balanceSheet()` shows the profit in retained earnings instead of current earnings.
- Revenue and expense **balances** (`Ledger::balance('4000')`) drop by the closed amount,
  since they are cumulative. Use `incomeStatement()` for period results.

## Reopening

```bash
php artisan ledger:reopen-period 2026-09
```

```php
Ledger::reopenPeriod('2026-09', reopenedBy: auth()->user());
```

This marks the period open, reverses its closing journal (dated inside the period), and
fires `PeriodReopened`. Closing again posts a new closing journal with the next sequence
number in its key.

## Recommended month-end routine

1. Post accruals, depreciation and deferred-revenue recognition for the month (scheduled
   commands with period-based keys).
2. Run `ledger:verify` and, while migrating, `ledger:reconcile`.
3. Review `ledger:trial-balance --as-of=2026-09-30` and the P&L.
4. `ledger:close-period 2026-09`.

Late entries for a closed month go into the current month, with a description that names
the original period. Reopen only when a correction must land in the original month.

## Who may close

The JSON API endpoints `POST periods/{period}/close` and `/reopen` require the
`manageLedger` gate. In your own admin, restrict them further, for example to a
"finance" role.
