# Chart of accounts

## Presets

### `standard`: general business

| Code | Account | Party |
|---|---|---|
| 1000 | Cash on hand | |
| 1010 | Petty cash | |
| 1050 | Payment gateway clearing | |
| 1100 | Bank accounts *(group)* | |
| 1101 | Main bank account | |
| 1200 | Accounts receivable | any |
| 1300 | Inventory | |
| 1400 | Prepaid expenses | |
| 1410 | Employee advances | any |
| 1500 | Input tax receivable | |
| 1600 | Fixed assets | |
| 1610 | Accumulated depreciation *(credit normal)* | |
| 1900 | Suspense | |
| 2000 | Accounts payable | any |
| 2100 | Customer deposits and wallet balances | any |
| 2200 | Tax payable | |
| 2300 | Accrued expenses | |
| 2400 | Salaries payable | any |
| 2500 | Loans payable | |
| 2600 | Refunds payable | any |
| 2700 | Deferred revenue | |
| 3000 | Opening balance equity *(role)* | |
| 3100 | Retained earnings *(role)* | |
| 3200 | Owner's capital | |
| 3300 | Owner's drawings *(debit normal)* | |
| 4000 | Sales revenue | |
| 4010 | Service revenue | |
| 4020 | Shipping revenue | |
| 4030 | Subscription revenue | |
| 4800 | Sales discounts *(debit normal)* | |
| 4810 | Sales returns *(debit normal)* | |
| 4900 | Other income | |
| 5000 | Cost of goods sold | |
| 5100–5150 | Salaries, rent, utilities, hosting, support, legal | |
| 5200 | Marketing and promotions | |
| 5210 | Discounts funded by the business | |
| 5300 | Delivery costs | |
| 5400 | Payment gateway fees | |
| 5410 | Bank charges | |
| 5500 | Depreciation | |
| 5900 | Miscellaneous expenses | |
| 5910 | Rounding differences *(role)* | |

### `marketplace`: multi-vendor commerce

The platform earns commission, in-house sales and the shipping it delivers.
Money it collects on behalf of vendors and delivery men is a liability, and
discounts the platform funds are expenses.

| Code | Account | Party |
|---|---|---|
| 1000 | Cash on hand | |
| 1010 | Payment gateway clearing | |
| 1020 | Bank (payout account) | |
| 1100 | Cash held by vendors | seller |
| 1110 | Cash held by delivery men | delivery_man |
| 1120 | COD held by couriers | |
| 1900 | Payment suspense | customer |
| 2000 | Customer wallet balances | customer |
| 2050 | Customer order prepayments | customer |
| 2100 | Payable to vendors | seller |
| 2101 | Vendor payouts in process | seller |
| 2110 | Payable to delivery men | delivery_man |
| 2111 | Delivery-man payouts in process | delivery_man |
| 2120 | Payable to auction owners | seller, customer |
| 2200 | Tax payable | |
| 2500 | Loyalty points liability | customer |
| 2600 | Refunds payable | customer |
| 3000 | Opening balance equity *(role)* | |
| 3100 | Retained earnings *(role)* | |
| 4000 | Commission revenue | |
| 4010 | In-house sales | |
| 4020 | Shipping revenue | |
| 4030 | Subscription revenue | |
| 4100 | Auction commission revenue | |
| 4110 | Auction entry fee revenue | |
| 5000 | Platform-funded coupon discounts | |
| 5010 | Free delivery subsidy | |
| 5020 | New-customer referral discount | |
| 5100 | Wallet top-up bonus | |
| 5110 | Referral earnings | |
| 5120 | Loyalty point conversions | |
| 5200 | Delivery-man charges | |
| 5210 | Courier fees | |
| 5300 | Payment gateway fees | |
| 5400 | Operating expenses *(group)* | |
| 5401–5406 | Salaries, hosting, support, marketing, rent/admin, legal | |
| 5900 | Wallet adjustments and goodwill | |
| 5910 | Rounding differences *(role)* | |

## Your own chart

Copy a preset and edit it:

```bash
php artisan vendor:publish --tag=ledger-charts      # → ledger/charts/*.php
```

```php
// config/ledger.php
'chart' => base_path('ledger/charts/my-company.php'),
```

A chart file returns a list of accounts. Parents must come before their children.

```php
return [
    ['code' => '1100', 'name' => 'Bank accounts', 'type' => 'asset', 'group' => true],
    ['code' => '1101', 'name' => 'City Bank', 'type' => 'asset', 'parent' => '1100'],
    ['code' => '1102', 'name' => 'bKash merchant', 'type' => 'asset', 'parent' => '1100'],
    ['code' => '2000', 'name' => 'Accounts payable', 'type' => 'liability', 'party' => ['supplier']],
    ['code' => '1610', 'name' => 'Accumulated depreciation', 'type' => 'asset', 'normal_side' => 'credit'],
    ['code' => '3000', 'name' => 'Opening balance equity', 'type' => 'equity', 'role' => 'opening_balance_equity'],
    ['code' => '3100', 'name' => 'Retained earnings', 'type' => 'equity', 'role' => 'retained_earnings'],
    ['code' => '5910', 'name' => 'Rounding differences', 'type' => 'expense', 'role' => 'rounding'],
];
```

| Key | Required | Notes |
|---|---|---|
| `code` | yes | String, unique, up to 32 characters. Keep it numeric-looking but always pass it as a string. |
| `name` | yes | |
| `type` | yes | `asset`, `liability`, `equity`, `revenue`, `expense` |
| `parent` | | Code of a **group** account of the **same type** |
| `group` | | `true` for a heading account that can't be posted to |
| `party` | | `null`, `'*'`, or a list of morph aliases |
| `normal_side` | | `debit` or `credit`; defaults from the type |
| `role` | | `rounding`, `opening_balance_equity` or `retained_earnings`. Each must exist once. |
| `is_system` | | Can't be deleted. Role accounts are always system accounts. |
| `currency`, `description`, `meta` | | Informational |

### Numbering convention

Keep the leading digit equal to the type (1 assets, 2 liabilities, 3 equity, 4 revenue,
5 expenses). Leave gaps so you can insert accounts later, and never reuse a code.

## Managing accounts in code

```php
$accounts = Ledger::accounts();

$accounts->create(['code' => '5407', 'name' => 'Training', 'type' => 'expense', 'parent' => '5400']);
$accounts->update(Ledger::account('5407'), ['name' => 'Staff training']);
$accounts->deactivate(Ledger::account('5407'));   // refuses new postings, keeps history
$accounts->activate(Ledger::account('5407'));
$accounts->delete(Ledger::account('5407'));       // only when it has no entries and no children
$accounts->tree();                                // roots with nested children
$accounts->findByRole('retained_earnings');
$accounts->seed('marketplace');                   // adds missing codes only
```

Rules the service enforces:

- Once an account has entries, its `type` and `normal_side` can't change, and it
  can't become a group. Renaming is fine.
- An account with entries, children or a role can't be deleted; deactivate it.
- An account holding a role can't be deactivated.
- A parent must be a group of the same type, and an account can't be moved under itself.

## Keep codes in one place in your app

Don't scatter `'2100'` across controllers. Give each app one class of constants:

```php
namespace App\Ledger;

final class Accounts
{
    public const CASH = '1000';
    public const GATEWAY_CLEARING = '1010';
    public const VENDOR_PAYABLE = '2100';
    public const COMMISSION = '4000';
    // ...
}
```

```php
->credit(Accounts::VENDOR_PAYABLE, $share, party: $seller)
```
