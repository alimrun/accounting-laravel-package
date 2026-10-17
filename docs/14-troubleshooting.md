# Troubleshooting

## Exceptions

All extend `Alimrun\Ledger\Exceptions\LedgerException`.

| Exception / message | Cause | Fix |
|---|---|---|
| `UnbalancedJournalException`: "does not balance: debits X, credits Y" | Lines don't add up beyond 0.000010 | Recompute the shares from stored columns; a component (tax, shipping, coupon) is usually missing on one side. |
| `InvalidJournalException`: "needs an idempotency key" | No `->idempotencyKey()` | Add a key that names the fact. |
| `InvalidJournalException`: "needs at least two lines" | Every other line was zero and got skipped | Don't post when there is nothing to move; check the amounts. |
| `InvalidJournalException`: "is a group account" | Posting to a heading such as 5400 | Post to a child account. |
| `InvalidJournalException`: "is inactive" | Account deactivated | Reactivate it, or post to its replacement. |
| `InvalidJournalException`: "exactly one positive side" | A `Line` built by hand with both or neither side | Use `debit()`/`credit()`. |
| `InvalidAmountException` | Negative, NaN or unparsable amount | Put negative values on the other side; pass strings. |
| `PartyMismatchException`: "needs a party" | Party account without `party:` | Add the customer, seller or other party. |
| `PartyMismatchException`: "does not take a party" | Party on a platform account | Remove it; the party belongs on the payable or receivable line. |
| `PartyMismatchException`: "takes seller parties; got [App\Models\User]" | Morph map missing, or the wrong model | Register the morph map alias; pass the right model. |
| `AccountNotFoundException` | Unknown code, or an **int** passed (treated as an id) | Pass the code as a string: `'4000'`. Seed the chart. |
| `AccountNotFoundException`: "No ledger account has the [rounding] role" | Custom chart without roles | Add `role` to three accounts, or set `ledger.accounts.*`. |
| `PeriodClosedException` | `occurred_at` is in a closed month | Post in the current month, or reopen the period. |
| `IdempotencyConflictException` | Same key reused for different money | Two facts share a key; make the key more specific (add the step, version or line). |
| `ImmutableRecordException` | `->update()`/`->delete()` on a journal or entry | Use `Ledger::reverse()`. |
| `QueryException` "Ledger entries are immutable" | Raw SQL update or delete, blocked by trigger | Same: reverse instead. |
| `QueryException` CHECK constraint `ledger_entries_one_side_check` | Raw insert bypassing the poster | Always post through `Ledger::post()`. |

## Symptoms

**`post()` returns `null`.** The ledger is disabled (`LEDGER_ENABLED=false` or
`Ledger::disable()`).

**`post()` returns an old journal and nothing changed.** The key was already posted with
identical lines. That's the idempotency guarantee working. Check
`$journal->wasRecentlyCreated`.

**A balance is off by an amount you can't explain.** Run `ledger:verify`. If it's clean,
the ledger is consistent, so look at what was posted:
`Ledger::statement($account, $party)` lists every line with its journal number and event.

**`ledger:verify` reports a balance row mismatch.** Something wrote to `ledger_balances`
outside the poster: a manual query, a partial restore, or a ledger on a different
connection from the rest of the transaction. Pause posting, run
`ledger:rebuild-balances --force`, and find the writer.

**Journal numbers have gaps.** You are on `numbering.strategy = fast`, or the sequence was
edited by hand. `gapless` never skips, because rollbacks also roll back the counter.

**The P&L for a closed month shows zero.** You are reading `Ledger::balance()` on revenue
accounts. Use `Ledger::incomeStatement($from, $to)`, which excludes closing journals.

**Wallet column and ledger disagree.** Run `ledger:reconcile`. During dual write this is
expected for flows not yet posting. Afterwards, something is still writing the column
directly.

**Postings are slow under load.** Look at lock waits. `ledger_sequences` contention means
switching numbering to `fast`; balance-row contention on a platform account means you set
`balances.track = all`; long transactions mean slow work is happening while the locks are held.

**MySQL migration fails creating triggers.** Binary logging is on. Grant `SUPER`, set
`log_bin_trust_function_creators=1`, or keep `immutable_triggers` off (Eloquent still guards).

## FAQ

**Can I post a journal in the past?** Yes, with `->occurredAt($date)`, as long as that month
isn't closed.

**Can one journal touch several parties?** Yes. Each line has its own party: customer,
seller and rider in the same delivered-order journal.

**Can I store per-line data like zone or shop?** Use `dimensions: ['zone_id' => 3]`, and
`segment` for the one dimension you report by everywhere.

**Multi-tenant SaaS?** Use one database per tenant (the ledger lives with the tenant's data),
or treat the tenant as a segment if tenants share one set of books.

**How do I delete test data in production?** You don't. Reverse it.
