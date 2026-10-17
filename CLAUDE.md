# CLAUDE.md: alimrun/laravel-ledger

A generic double-entry general ledger package for Laravel 11–13 (PHP 8.2+), shared by
all of the owner's projects (marketplaces such as 6Valley and 6amMart, ERP panels,
SaaS apps). It must stay **project-agnostic**: project-specific posting rules, account
constants and reconcilers belong in each application, never here.

User-facing docs live in `docs/` (index: `docs/README.md`). Integration rules for
host applications live in `stubs/CLAUDE.ledger.md`, which is published with
`php artisan vendor:publish --tag=ledger-claude`.

## Commands

```bash
composer install
vendor/bin/phpunit                      # full suite: SQLite in-memory, DB triggers on
vendor/bin/phpunit --filter PeriodTest  # one class
find src -name '*.php' -exec php -l {} \;
```

Tests run on Orchestra Testbench with the `marketplace` chart seeded in
`tests/TestCase.php::afterRefreshingDatabase()`. MySQL and PostgreSQL aren't covered by
CI yet. Before relying on lock, trigger or CHECK behaviour, run the suite against them.

## Architecture

```
Facades/Ledger ──▶ LedgerManager (entry point, scoped singleton)
                     ├─ journal() ─▶ Posting/JournalBuilder ─▶ JournalDraft (+ Line DTOs)
                     ├─ post() ───▶ Services/JournalPoster   validate · balance · idempotency · write
                     │                 ├─ PeriodService      assertOpen (shared lock), close/reopen
                     │                 ├─ BalanceService     sorted row locks, running totals, balance queries
                     │                 └─ NumberGenerator    number assigned LAST (gapless | fast)
                     ├─ reverse(), openingBalance()
                     ├─ balance(), lockBalance()
                     ├─ Reports/*  TrialBalance, IncomeStatement, BalanceSheet, AccountStatement, PartyBalances
                     ├─ IntegrityChecker (verify), ReconciliationService (reconcile)
                     └─ AccountService (chart: cache, seed, create/update/delete rules, tree, roles)
Models/       Account, Journal*, Entry*, Balance, Period, Sequence, ReconciliationIssue   (* Immutable)
Support/      Amount (BigDecimal, scale 12), Ref (morph type+id), Expectation, Events, Table
Concerns/     HasLedger trait for host models
Console/      ledger:install|seed-chart|trial-balance|verify|reconcile|close-period|reopen-period|rebuild-balances
Http/         optional JSON API (routes/api.php), gated by viewLedger / manageLedger
resources/charts/  standard.php, marketplace.php presets
database/migrations/  tables + optional immutability triggers (MySQL, PostgreSQL, SQLite)
```

### Posting pipeline (`JournalPoster::post`)

1. Event and key present (or a ULID when `idempotency.required` is false).
2. `resolve()`: account exists, not a group, active, party rule, one positive side.
   Reversals and closing journals skip the active and party checks.
3. `balance()`: exact sum; a residual ≤ tolerance adds a line on the `rounding` role
   account, otherwise `UnbalancedJournalException`; then at least two lines.
4. `hash()`: sha256 over the event and sorted (account, party, debit, credit, tag).
5. An existing key returns the journal (strict mode throws on a hash mismatch).
6. `write()` inside `LedgerManager::transaction()`: assertOpen → insert journal (number
   null) → insert entries → `BalanceService::apply` → `NumberGenerator::assign` →
   `JournalPosted`. A `UniqueConstraintViolationException` on a concurrent key re-reads
   and replays.

## Invariants: never break these

- A journal balances exactly, has at least two lines, and every line has exactly one positive side.
- Journals and entries are append-only. The **only** permitted update is
  `NumberGenerator::assign` setting `number` while it is null (the triggers allow exactly
  this). Don't add other updates; add a reversal instead.
- `ledger_balances` changes only through `BalanceService::apply` and `rebuild`.
  Rows are locked in sorted `(account_id, party_type, party_id)` order.
- The gapless sequence lock is taken **last** in a posting.
- Postings nest in the caller's transaction. Never commit, open a separate connection or
  dispatch synchronous side effects mid-posting. Events implement
  `ShouldDispatchAfterCommit` and go through `Support\Events::dispatch()` (lazy dispatcher,
  so `Event::fake()` works).
- Money is `Brick\Math\BigDecimal` at scale 12 (`Amount::of`), stored as `DECIMAL(30,12)` in every money column (including `exchange_rate`). Changing the scale means changing `Amount::SCALE`, the migration, the model `decimal:12` casts and the integrity tolerance together. No float arithmetic anywhere;
  SQL `SUM()` results are wrapped in `Amount::of()`, because SQLite returns floats.
- Account arguments: a string is a **code**, an int is an **id**. Keep that contract.
- Closing journals (`origin = closing`) are excluded from the income statement and included
  everywhere else.
- Report classes are read-only and serialise amounts as 12-decimal strings.

## Conventions

- Match the existing style: typed properties, constructor promotion, `final` for
  value objects and reports, short docblocks that explain *why*, Laravel naming.
- Every new validation failure is a `LedgerException` subclass with a message that names
  the account code or key involved.
- Every new config key is documented in `config/ledger.php` **and**
  `docs/11-configuration.md`.
- Every public API change is reflected in the facade `@method` docblock, `README.md` and the
  relevant `docs/` page.
- Table names always go through `Support\Table::name()`; models use `Concerns\LedgerModel`.
- Keep the package dependency-light: `illuminate/*` components and `brick/math` only.
  Don't depend on `laravel/framework`-only classes in `src/` (that's why `Support\Events`
  exists instead of the `Dispatchable` trait).

## Adding features

- **New report**: a class in `src/Reports` extending `Report` with a static `generate()`, a
  method on `LedgerManager`, a facade `@method`, an API route if useful, tests, and a docs section in
  `docs/07-balances-and-reports.md`.
- **New chart preset**: `resources/charts/{name}.php`; it must contain the three roles
  (`rounding`, `opening_balance_equity`, `retained_earnings`), and the parents must come
  first. Add a seeding test.
- **New column on entries or journals**: a new migration (never edit the shipped ones once
  released), a poster write, a `Line`/`JournalDraft` field, a builder method, and the trigger
  migration only if it affects mutability.
- **Schema changes** must stay compatible with MySQL 8, MariaDB, PostgreSQL and SQLite.

## Testing expectations

Every behaviour change gets a feature test in `tests/Feature`. Tests that post should
leave `Ledger::verify()` empty. Use `tests/Fixtures/OrderDeliveredPosting.php` (the 202 /
222.00 voucher from the 6Valley plan) as the reference scenario.

## Integrating into a host application (summary)

When asked to wire this package into a project, follow `docs/05-integration-guide.md`:

1. `composer require alimrun/laravel-ledger`, `ledger:install --chart=…`, morph map whose
   aliases match the chart's party types, `HasLedger` on party and source models.
2. Publish `ledger-claude` and add `@.claude/ledger.md` to the host's `CLAUDE.md`.
3. Build the posting catalogue (every money flow → event, key, source, lines).
4. Create `app/Ledger/Accounts.php`, one `Posting` per event, and synchronous listeners
   that post inside the business transaction after locking the source row.
5. Replace balance reads with `Ledger::balance()` / `lockBalance()`. Legacy columns become
   `JournalPosted` projections.
6. For existing data: opening balances, then dual write per domain with reconcilers,
   then switch reads (`docs/09-reconciliation-and-migration.md`).
7. Golden-number and replay tests per rule; schedule `ledger:verify`.
