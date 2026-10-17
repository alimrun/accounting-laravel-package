# Installation

## Requirements

- PHP 8.2+
- Laravel 11, 12 or 13
- MySQL 8.0.16+, MariaDB 10.5+, PostgreSQL 13+ or SQLite 3.35+ (SQLite is fine for tests)

`brick/math` is installed automatically and does the exact decimal arithmetic.

## 1. Require the package

The package is shared between your projects rather than published on Packagist.
Pick one of these:

**Local path** (projects on the same machine, symlinked so changes show up immediately):

```jsonc
// composer.json of the application
"repositories": [
    { "type": "path", "url": "../accounting-laravel-package", "options": { "symlink": true } }
]
```

**Private Git repository** (servers, CI, other developers):

```jsonc
"repositories": [
    { "type": "vcs", "url": "git@github.com:your-account/laravel-ledger.git" }
]
```

Then:

```bash
composer require alimrun/laravel-ledger:@dev     # or a tag such as ^1.0 once you tag releases
```

The service provider and the `Ledger` facade alias are discovered automatically.

## 2. Publish the config (optional)

```bash
php artisan vendor:publish --tag=ledger-config
```

Everything has a sensible default; see [Configuration](11-configuration.md).
Set anything that must be on before the migrations run now, such as
`LEDGER_IMMUTABLE_TRIGGERS=true` or a custom `table_prefix`.

## 3. Migrate and seed the chart

```bash
php artisan ledger:install --chart=standard      # general business
php artisan ledger:install --chart=marketplace   # multi-vendor commerce
php artisan ledger:install --chart=/path/to/my-chart.php
```

This runs `migrate` and creates the accounts. It is safe to run again, because existing
account codes are left untouched.

If you'd rather keep the migrations in your own `database/migrations`, publish them
and turn off auto-loading:

```bash
php artisan vendor:publish --tag=ledger-migrations
```

```php
// config/ledger.php
'database' => ['run_migrations' => false, /* ... */],
```

## 4. Register a morph map

Parties and sources are stored as `type` + `id`. The type is the model's morph class,
so without a morph map you would store `App\Models\User` in millions of rows, and
renaming the class would orphan them.

```php
// app/Providers/AppServiceProvider.php
use Illuminate\Database\Eloquent\Relations\Relation;

public function boot(): void
{
    Relation::enforceMorphMap([
        'customer' => \App\Models\User::class,
        'supplier' => \App\Models\Supplier::class,
        'employee' => \App\Models\Employee::class,
        'order'    => \App\Models\Order::class,
        'invoice'  => \App\Models\Invoice::class,
    ]);
}
```

The aliases must match the `party` types in your chart of accounts. For example,
the marketplace chart expects `customer`, `seller` and `delivery_man`.

## 5. Add the trait to party and source models

```php
use Alimrun\Ledger\Concerns\HasLedger;

class User extends Authenticatable
{
    use HasLedger;
}
```

This gives you `$user->ledgerBalance('2100')`, `$user->ledgerStatement('2100')`,
`$user->ledgerEntries()` and, on source models, `$order->ledgerJournals()`.

## 6. Check it works

```bash
php artisan tinker
>>> Ledger::journal('test')->key('install-check')->debit('1000', 1)->credit('3200', 1)->post()->number
=> "JV-2026-00000001"
>>> Ledger::reverse(Ledger::find('install-check'))
php artisan ledger:verify
```

## 7. Schedule the checks

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('ledger:verify')->dailyAt('02:00')->onOneServer();
Schedule::command('ledger:reconcile')->dailyAt('02:30')->onOneServer();
```

## Publishable files

| Tag | What | Where |
|---|---|---|
| `ledger-config` | `config/ledger.php` | `config/` |
| `ledger-migrations` | The two migrations | `database/migrations/` |
| `ledger-charts` | The chart presets, to copy and edit | `ledger/charts/` |
| `ledger-claude` | Integration rules for Claude Code | `.claude/ledger.md` |
