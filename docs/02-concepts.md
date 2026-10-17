# Concepts

## Double entry in one paragraph

Every movement of value is recorded twice: once where it comes from (a **credit**)
and once where it goes to (a **debit**). The two always match, so the books as a
whole always sum to zero. If they don't, something is wrong, and the ledger can
prove it. That's the property your old wallet columns couldn't give you: when a
seller's balance went up, nothing tied that increase to the money that came in.

## Accounts and their types

| Type | Holds | Normal side | Goes up with |
|---|---|---|---|
| Asset | What the business owns or is owed: cash, bank, receivables, cash held by riders | Debit | Debit |
| Liability | What the business owes: payables to vendors, customer wallets, tax | Credit | Credit |
| Equity | The owners' stake: capital, retained earnings, opening balances | Credit | Credit |
| Revenue | What the business earned: sales, commission, fees | Credit | Credit |
| Expense | What it cost: salaries, discounts it funded, gateway fees | Debit | Debit |

A **balance** is reported positive on the account's normal side. Ledger
`balance('2100', $seller) = 205.00` means the business owes that seller 205.00.

A **contra account** sits in a type but on the opposite side, such as
accumulated depreciation (an asset with credit normal) or sales discounts (revenue
with debit normal). Set `normal_side` on the account to make one.

A **group account** (`is_group`) only exists to organise children, such as
"Operating expenses" with Salaries and Rent below it. You can't post to a group, but
its balance includes all its children.

## Journals and entries

A **journal** (`ledger_journals`) is one business event: "order 100245 was
delivered". It has:

| Field | Meaning |
|---|---|
| `number` | `JV-2026-00000001`, gapless per year by default |
| `event` | A dotted name you choose: `order.delivered`, `payout.approved` |
| `idempotency_key` | Unique. Names the fact once, such as `order:100245:delivered` |
| `source_type` / `source_id` | The record that caused it (order, refund, payout) |
| `occurred_at`, `period` | When it happened, and its month `2026-10` |
| `segment` | Business unit, module, branch or channel |
| `currency`, `exchange_rate` | Display currency of the event; lines are always in the base currency |
| `origin` | `live`, `manual`, `opening`, `backfill` or `closing` |
| `reversal_of_id` | Set when this journal reverses another |
| `payment_method`, `transaction_ref`, `description`, `meta`, `created_by_*` | Audit context |

An **entry** (`ledger_entries`) is one line of a journal: an account, a debit
*or* a credit (never both, never zero), and its context:

| Field | Meaning |
|---|---|
| `party_type` / `party_id` | Who this line is about (customer #812, seller #44) |
| `tag` | Short label for the component: `commission`, `tax`, `shipping`, `coupon` |
| `segment` | Inherited from the journal unless set on the line |
| `reference_type` / `reference_id` | Another record the line points at, such as an order line or invoice |
| `memo` | Free text |
| `dimensions` | JSON for anything else, such as `{"zone_id": 3, "shop_id": 12}` |

## Control accounts and parties

You don't create one account per customer. You create one **control account**,
"Customer wallet balances", and put the customer on each line as the **party**.
The ledger keeps a locked running total per (account, party), which is the
**sub-ledger**.

Each account declares which parties it accepts:

| Chart setting | Rule |
|---|---|
| no `party` | Lines must *not* have a party (platform revenue, bank, tax) |
| `'party' => ['seller']` | Every line must have a `seller` party |
| `'party' => ['seller', 'customer']` | Either of these |
| `'party' => '*'` | Any party, but one is required |

That rule catches the most common integration bug: posting a seller's money to a
platform account, or the other way round.

## Segments and dimensions

A **segment** is the one dimension every report can filter by: retail and
wholesale, branch A and branch B, web and POS. It is an indexed column on every
line. Use **tags** for the component of a line and **dimensions** for anything
else you might later want to group by.

## Idempotency

Gateways send callbacks twice, admins double-click, queues retry. Each journal's
idempotency key is unique in the database, so:

- Posting the same key again returns the **existing** journal and moves nothing.
- Posting the same key with **different lines** throws
  `IdempotencyConflictException` (strict mode). That is almost always a bug where
  two different facts share a key.

Choose keys that describe the fact, not the request: `payment:{payment_id}:captured`,
not `request:{uuid}`.

## Append-only

Journals and entries can't be updated or deleted. Eloquent throws
`ImmutableRecordException`, and with `immutable_triggers` on, the database refuses
raw SQL as well. To correct a journal, **reverse** it (a mirror journal linked by
`reversal_of_id`) and post the right one with a new key.

## Origins

| Origin | Posted by | In the income statement |
|---|---|---|
| `live` | Your application as events happen | Yes |
| `manual` | An accountant through the API or your admin | Yes |
| `opening` | Cut-over balances against opening balance equity | Yes, if it hits revenue or expense (it usually doesn't) |
| `backfill` | A replay of history for reporting | Yes |
| `closing` | Period close into retained earnings | **No** |

## Periods

Every journal falls in a monthly period, `YYYY-MM`. Closing a period moves its
revenue and expense balances into retained earnings and blocks further postings
dated in that month. See [Periods](08-periods.md).

## Base currency

Every amount is stored in one base currency (`LEDGER_CURRENCY`, default USD) as
`DECIMAL(30,12)`: 12 decimal places, 18 integer digits. A journal can record the currency the customer saw and the
exchange rate, but the lines are always converted to the base currency *before*
posting. Convert once, in your code, using the rate you stored on the order;
never inside a posting rule from a live rate.
