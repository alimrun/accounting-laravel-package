# Database and performance

## Tables

| Table | Rows | Written by |
|---|---|---|
| `ledger_accounts` | Tens to hundreds | `AccountService` |
| `ledger_journals` | One per business event | The poster; append-only |
| `ledger_entries` | Two or more per journal (about five per marketplace order) | The poster; append-only |
| `ledger_balances` | One per (account, party), or per account with `track = all` | The poster, under lock |
| `ledger_periods` | One per month | Posting (insert-if-missing) and close/reopen |
| `ledger_sequences` | One per numbering window | Gapless numbering |
| `ledger_reconciliation_issues` | Differences found | Reconcilers |

Money columns are `DECIMAL(24,6)`: 18 integer digits, 6 decimals.

## What one posting does

Inside one transaction (a savepoint if you are already in one):

1. Reads the period row with a **shared lock**, inserting it if missing. A concurrent
   close waits for you, and you wait for a close in progress.
2. Inserts the journal (number still null) and its entries.
3. Locks and updates the balance rows the lines touch, **sorted by account and party**.
   Two postings can't wait on each other in a cycle.
4. Assigns the number. With `gapless`, it locks the year's sequence row **last**,
   so the global lock is held as briefly as possible.
5. Fires `JournalPosted` after the outer commit.

All locks are held until **your** transaction commits, so keep transactions that post short.
Do slow work (HTTP calls, emails) outside them or after commit.

## Hot rows and how they're avoided

- **Platform accounts** (revenue, bank, tax) appear on almost every journal. With the
  default `balances.track = party`, they get no balance row, so there is nothing to lock.
  Their totals are aggregated from indexed entries.
- **Party rows** (one customer, one seller) are only contended by that party's own
  activity, which is the contention you want: it stops a double spend.
- **The gapless sequence** is the one global lock. Every posting takes it briefly at the
  end. If postings queue on it, switch to `numbering.strategy = fast`.

### Deadlock notes

- `lockBalance()` locks one balance row early in your transaction. If the same
  transaction then posts, the poster takes the remaining rows in sorted order, which is safe.
- Avoid locking balance rows in different orders yourself, for example locking customer
  A then B in one request and B then A in another.
- With MySQL, a deadlock in a nested transaction aborts the whole outer transaction.
  Retry at the outermost level (`DB::transaction($fn, attempts: 3)`).

## Indexes

| Index | Serves |
|---|---|
| `entries (account_id, occurred_at)` | Statements, balances as of a date, trial balance |
| `entries (party_type, party_id, account_id)` | Party statements and balances |
| `entries (reference_type, reference_id)` | "Lines about this invoice" |
| `entries (segment, account_id, occurred_at)` | Segment reports |
| `entries (period, account_id)` | Period close |
| `entries (tag)` | Component breakdowns |
| `journals (idempotency_key)` unique | Idempotency |
| `journals (source_type, source_id)` | "What did this order post" |
| `journals (event)`, `(occurred_at)`, `(period)`, `(segment)`, `(origin)`, `(transaction_ref)` | Journal list filters |
| `balances (account_id, party_type, party_id)` unique | O(1) balances and locking |

## Database-level guarantees

- **Uniqueness**: idempotency key, journal number, one reversal per journal, one balance
  row per (account, party), one row per period.
- **CHECK** `(debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0)` on entries
  (MySQL 8.0.16+, MariaDB, PostgreSQL).
- **Foreign keys** with `RESTRICT`: an account or journal that is referenced can't be deleted.
- **Triggers** (`immutable_triggers`): refuse UPDATE and DELETE on entries, and on journals
  once numbered. On MySQL with binary logging, the migrating user needs `SUPER`
  or the server needs `log_bin_trust_function_creators = 1`.

## Growth

Plan for about five entry rows per order. At 2M orders a year that is 10M entries a year,
which these indexes handle comfortably on MySQL 8. Beyond about 50M rows:

- Partition `ledger_entries` by year (`RANGE` on `YEAR(occurred_at)`). This needs
  `occurred_at` in the primary key; plan it as a migration in your app.
- Cache heavy reports (the P&L for closed periods never changes).
- Run reports on a read replica. Every report method only reads.

## Connections

Keep `database.connection` null so the ledger uses your default connection. If you put the
ledger on another connection or database, postings are **no longer atomic** with your
business writes, so you'd need your own outbox or retries.
