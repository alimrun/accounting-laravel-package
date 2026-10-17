# JSON API

An optional set of endpoints for an accounting screen or an external tool.

## Enabling

```env
LEDGER_ROUTES=true
```

```php
// config/ledger.php
'routes' => [
    'enabled' => env('LEDGER_ROUTES', false),
    'prefix' => 'api/ledger',
    'middleware' => ['api', 'auth:sanctum'],
    'gates' => ['view' => 'viewLedger', 'manage' => 'manageLedger'],
],
```

Define both gates. If you don't, they are **allowed only in the `local` environment**.

```php
// AppServiceProvider::boot()
Gate::define('viewLedger', fn (User $user) => $user->hasPermission('accounting.view'));
Gate::define('manageLedger', fn (User $user) => $user->hasPermission('accounting.manage'));
```

All routes are named `ledger.*`.

## Accounts

| Method | Path | Gate | Notes |
|---|---|---|---|
| GET | `accounts` | view | `?tree=1` for nested children |
| GET | `accounts/{code}` | view | Includes `balance` |
| POST | `accounts` | manage | `code, name, type, normal_side?, parent?, party?, group?, currency?, description?` |
| PATCH | `accounts/{code}` | manage | Same fields, all optional |
| POST | `accounts/{code}/deactivate` | manage | |
| POST | `accounts/{code}/activate` | manage | |

## Journals

| Method | Path | Gate | Notes |
|---|---|---|---|
| GET | `journals` | view | Paginated; filters below |
| GET | `journals/{id}` | view | With entries, accounts, reversal links |
| POST | `journals` | manage | Manual journal |
| POST | `journals/{id}/reverse` | manage | `reason?, occurred_at?` |

`GET journals` filters: `event`, `segment`, `origin`, `number`, `transaction_ref`,
`account` (code), `party_type` + `party_id`, `source_type` + `source_id`, `from`, `to`,
`per_page` (max 200). Each row includes `total` (sum of debits).

### Manual journal

```http
POST /api/ledger/journals
Content-Type: application/json

{
  "idempotency_key": "rent-2026-10",
  "event": "expense.rent",
  "occurred_at": "2026-10-01",
  "description": "October office rent",
  "segment": "dhaka-branch",
  "transaction_ref": "CHQ-00921",
  "lines": [
    { "account": "5110", "debit": "1500.00", "tag": "rent", "memo": "Head office" },
    { "account": "1101", "credit": "1500.00" },
    { "account": "1200", "debit": "40.00", "party_type": "customer", "party_id": 812 }
  ]
}
```

- The stored key is `manual:{idempotency_key}`, so the client should send a stable key per
  form submission to make double-submits harmless.
- `origin` is `manual`, and `created_by` is the authenticated user.
- Validation errors from the ledger (unbalanced, wrong party, closed period) are thrown as
  `LedgerException`s. Map them to HTTP 422 in your exception handler:

```php
// bootstrap/app.php
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->render(fn (\Alimrun\Ledger\Exceptions\LedgerException $e) =>
        response()->json(['message' => $e->getMessage()], 422));
})
```

## Reports

| Method | Path | Query |
|---|---|---|
| GET | `reports/trial-balance` | `as_of, from, segment, include_zero` |
| GET | `reports/income-statement` | `from, to, segment` |
| GET | `reports/balance-sheet` | `as_of, segment` |
| GET | `reports/statement` | `accounts[]` (required), `party_type, party_id, from, to, segment, side, limit` (default 5000) |
| GET | `reports/party-balances/{code}` | `as_of, include_zero` |

## Periods and reconciliation

| Method | Path | Gate |
|---|---|---|
| GET | `periods` | view |
| POST | `periods/{YYYY-MM}/close` | manage |
| POST | `periods/{YYYY-MM}/reopen` | manage |
| GET | `reconciliation-issues` | view (`?include_resolved=1`) |
