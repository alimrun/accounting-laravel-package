# Ledger integration rules (alimrun/laravel-ledger)

This project records money with the `alimrun/laravel-ledger` double-entry ledger.
Follow these rules whenever code creates, moves, refunds, pays out or reports money.
Full docs: `vendor/alimrun/laravel-ledger/docs/` (start with `05-integration-guide.md`
and `06-recipes.md`).

## Non-negotiable

1. **Every money movement posts a journal** through `Ledger::post()` (builder or a
   `Posting` class). Never write wallet, balance or earning columns directly. If such
   columns exist, only the `JournalPosted` projector writes them.
2. **Post inside the business transaction**, on the default connection, in a synchronous
   listener or direct call. Never post from a queued job or a `ShouldQueue` listener.
3. **Lock the source row** (`lockForUpdate()`) and re-check its state before a status
   transition that posts.
4. **Every journal has an idempotency key naming the business fact**:
   `order:{id}:delivered`, `payment:{payment_request_id}:captured`,
   `refund:{id}:approved`, `withdraw:{id}:requested`. Never a random UUID or a timestamp.
   Every entry point for the same fact uses the same key.
5. **Account codes are strings**, taken from `App\Ledger\Accounts` constants. An int is
   treated as an account *id*.
6. **Amounts**: strings or `Amount::of()` / `BigDecimal` arithmetic; never floats, never
   negative (put them on the other side). Zero lines are skipped automatically.
7. **Parties**: lines on party accounts (wallets, payables, receivables, cash held by
   others) carry the party model; platform revenue, expense, bank and tax lines have none.
8. **Reuse stored numbers.** Posting rules read the columns the app already computed
   (commission, tax, shares). They never recompute business maths.
9. **Corrections are reversals**: `Ledger::reverse($journal, 'reason')`, then post the
   correct journal with a new key. Never update or delete ledger rows.
10. **Spending a balance** (wallet pay, withdrawal, credit limit): call
    `Ledger::lockBalance($account, $party)` and post in the same transaction.

## Where things go

| What | Where |
|---|---|
| Account code constants | `app/Ledger/Accounts.php` |
| One posting rule per event | `app/Ledger/Postings/{Event}Posting.php` extending `Alimrun\Ledger\Posting\Posting` |
| Listeners that post | `app/Ledger/Listeners/Post{Event}Journal.php` (synchronous) |
| Projections of legacy columns | `app/Ledger/Listeners/Project*.php` listening to `JournalPosted` |
| Reconcilers | `app/Ledger/Reconcilers/*`, registered in `config/ledger.php` |
| Posting catalogue | `docs/ledger-postings.md`: event, key, source, lines |

## Adding a new money flow

1. Add a row to the posting catalogue (event, key, source, lines with parties and tags).
2. Check the debits and credits against `docs/06-recipes.md` and confirm they balance by hand.
3. Write the `Posting` class: `event()`, `idempotencyKey()`, `build()` with `source()`,
   `occurredAt()` (when it happened) and `segment()` if the app uses segments.
4. Call it from the flow inside the transaction, after the state change.
5. If the flow can be undone, decide whether it's a reversal or a new event.
6. Tests: a golden-number test (exact lines), a replay test (posting twice gives one
   journal), and `Ledger::verify()` returns `[]`.

## Template

```php
final class ExamplePosting extends Posting
{
    public function __construct(private readonly Model $source) {}

    public function event(): string { return 'example.happened'; }

    public function idempotencyKey(): string { return "example:{$this->source->id}:happened"; }

    public function build(JournalBuilder $journal): void
    {
        $journal->source($this->source)
            ->occurredAt($this->source->happened_at)
            ->debit(Accounts::SOMETHING, $this->source->amount, party: $this->source->customer, tag: 'x')
            ->credit(Accounts::REVENUE, $this->source->amount, tag: 'x');
    }
}
```

## Reading money

- Balances: `Ledger::balance($accounts, $party, asOf:, segment:, side:)`, which returns a `BigDecimal`.
- Statements: `Ledger::statement($accounts, $party, from:, to:)`.
- Reports: `Ledger::trialBalance()`, `incomeStatement()`, `balanceSheet()`, `partyBalances()`.

## Before finishing a change that touches money

- [ ] Posts inside the transaction, with a deterministic key
- [ ] No direct writes to balance columns
- [ ] Party on every party-account line, and none on platform lines
- [ ] Golden-number and replay tests added; `php artisan ledger:verify` passes locally
