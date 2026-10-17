# Configuration

`config/ledger.php` (publish with `php artisan vendor:publish --tag=ledger-config`).

| Key | Env | Default | What it does |
|---|---|---|---|
| `enabled` | `LEDGER_ENABLED` | `true` | Kill switch. When false, `post()` and `reverse()` write nothing and return `null`. |
| `database.connection` | `LEDGER_DB_CONNECTION` | `null` | Connection for the ledger tables. Leave null so postings share your app's transactions. |
| `database.table_prefix` | | `ledger_` | Prefix for all seven tables. Set it before migrating. |
| `database.run_migrations` | | `true` | Load the package migrations automatically. Turn off if you publish them. |
| `database.immutable_triggers` | `LEDGER_IMMUTABLE_TRIGGERS` | `false` | Create DB triggers that refuse UPDATE and DELETE on journals and entries. Set it before migrating. |
| `currency` | `LEDGER_CURRENCY` | `USD` | Base currency of every amount. Stored on each journal unless overridden. Amounts are always `DECIMAL(30,12)`. |
| `rounding.tolerance` | | `0.000010` | Largest imbalance absorbed by the `rounding` account; also the reconciliation tolerance. |
| `accounts.rounding` | | `null` | Code to use instead of the account with the `rounding` role. |
| `accounts.opening_balance_equity` | | `null` | Same for opening balances. |
| `accounts.retained_earnings` | | `null` | Same for period close. |
| `numbering.strategy` | | `gapless` | `gapless`: locked counter, never skips. `fast`: number from the journal id, no lock, may skip on rollback. |
| `numbering.format` | | `JV-{Y}-{number}` | Tokens `{Y}`, `{y}`, `{m}`, `{number}`. |
| `numbering.pad` | | `8` | Zero-padding of `{number}`. |
| `numbering.reset` | | `yearly` | Counter window for `gapless`: `yearly`, `monthly`, `never`. |
| `balances.track` | | `party` | `party`: running totals only for lines with a party. `all`: also for platform accounts (enables `lockBalance()` on them, but creates hot rows). |
| `idempotency.required` | | `true` | Refuse journals without a key. When false, a ULID is generated. |
| `idempotency.strict` | | `true` | Throw when a key is replayed with different lines, instead of silently returning the first journal. |
| `strict_parties` | | `true` | Enforce each account's party rule. |
| `posting.attempts` | | `3` | Deadlock retries when the ledger opens the outermost transaction. |
| `chart` | | `standard` | Preset name or a path to a chart file, used by `ledger:install` and `ledger:seed-chart`. |
| `reconcilers` | | `[]` | Reconciler classes run by `ledger:reconcile`. |
| `routes.enabled` | `LEDGER_ROUTES` | `false` | Register the JSON API. |
| `routes.prefix` | | `api/ledger` | |
| `routes.middleware` | | `['api', 'auth']` | |
| `routes.gates.view` / `.manage` | | `viewLedger` / `manageLedger` | Gate abilities checked by the API. Allowed only locally unless you define them. |

## Recommended production settings

```env
LEDGER_ENABLED=true
LEDGER_CURRENCY=USD
LEDGER_IMMUTABLE_TRIGGERS=true   # if the DB user may create triggers
```

For high posting volume (thousands per minute), also set:

```php
'numbering' => ['strategy' => 'fast', 'format' => 'JV-{number}', 'pad' => 10],
```

## Commands

| Command | Does |
|---|---|
| `ledger:install {--chart=} {--no-migrate}` | Migrate and seed the chart |
| `ledger:seed-chart {chart?}` | Add missing accounts from a chart |
| `ledger:trial-balance {--as-of=} {--from=} {--segment=}` | Print the trial balance; exit 1 if unbalanced |
| `ledger:verify` | Integrity checks; exit 1 on any problem |
| `ledger:reconcile` | Run reconcilers; exit 1 on any difference |
| `ledger:close-period {YYYY-MM}` | Close a month into retained earnings |
| `ledger:reopen-period {YYYY-MM}` | Reopen and reverse the closing journal |
| `ledger:rebuild-balances {--force}` | Recompute `ledger_balances` from entries (pause posting first) |
