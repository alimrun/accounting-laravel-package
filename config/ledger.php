<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Kill switch
    |--------------------------------------------------------------------------
    |
    | When disabled, Ledger::post() writes nothing and returns null, so a
    | rollout can be switched off without a deploy. Reads keep working.
    |
    */

    'enabled' => env('LEDGER_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    |
    | Postings are atomic with your business change only when the ledger
    | shares the connection your application writes with. Leave
    | "connection" null to use the default connection.
    |
    | "immutable_triggers" adds database triggers that refuse UPDATE and
    | DELETE on journals and entries (MySQL, PostgreSQL, SQLite). On MySQL
    | with binary logging this needs SUPER or log_bin_trust_function_creators.
    |
    */

    'database' => [
        'connection' => env('LEDGER_DB_CONNECTION'),
        'table_prefix' => 'ledger_',
        'run_migrations' => true,
        'immutable_triggers' => env('LEDGER_IMMUTABLE_TRIGGERS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Base currency
    |--------------------------------------------------------------------------
    |
    | Every line is stored in this currency at 6 decimal places. A journal
    | may record the display currency and exchange rate it came from.
    |
    */

    'currency' => env('LEDGER_CURRENCY', 'USD'),

    /*
    |--------------------------------------------------------------------------
    | Rounding
    |--------------------------------------------------------------------------
    |
    | A journal whose debits and credits differ by no more than the
    | tolerance gets one balancing line on the account with the "rounding"
    | role. A larger difference is refused.
    |
    */

    'rounding' => [
        'tolerance' => '0.000010',
    ],

    /*
    |--------------------------------------------------------------------------
    | System account roles
    |--------------------------------------------------------------------------
    |
    | Chart presets mark these accounts with a role. Set a code here to
    | override the role lookup.
    |
    */

    'accounts' => [
        'rounding' => null,
        'opening_balance_equity' => null,
        'retained_earnings' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Journal numbering
    |--------------------------------------------------------------------------
    |
    | "gapless" takes a row-locked counter per reset window, so numbers never
    | skip, at the cost of serialising postings until commit.
    | "fast" derives the number from the journal id: no lock, but a rolled
    | back posting leaves a gap.
    |
    | Tokens: {Y} {y} {m} {number}
    |
    */

    'numbering' => [
        'strategy' => 'gapless',
        'format' => 'JV-{Y}-{number}',
        'pad' => 8,
        'reset' => 'yearly', // yearly | monthly | never
    ],

    /*
    |--------------------------------------------------------------------------
    | Running balances
    |--------------------------------------------------------------------------
    |
    | "party": keep locked running totals only for lines that carry a party
    | (customer, vendor, employee...). Platform totals come from indexed
    | aggregates, which avoids one hot row per revenue account.
    | "all": also keep totals for accounts without a party.
    |
    */

    'balances' => [
        'track' => 'party',
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    |
    | "required": every journal must carry an idempotency key.
    | "strict": replaying a key with different lines throws instead of
    | silently returning the first journal.
    |
    */

    'idempotency' => [
        'required' => true,
        'strict' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Parties
    |--------------------------------------------------------------------------
    |
    | With strict parties, an account that declares party types must get a
    | matching party on every line, and an account without party types
    | refuses one. Party types are morph classes, so register a morph map
    | ("customer" => User::class) to keep them short and stable.
    |
    */

    'strict_parties' => true,

    /*
    |--------------------------------------------------------------------------
    | Posting
    |--------------------------------------------------------------------------
    |
    | Deadlock retries apply only when the ledger opens the outermost
    | transaction itself.
    |
    */

    'posting' => [
        'attempts' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Chart of accounts
    |--------------------------------------------------------------------------
    |
    | Preset seeded by `php artisan ledger:install`: "standard" (general
    | business) or "marketplace" (multi-vendor commerce), or a path to a PHP
    | file that returns an array of accounts.
    |
    */

    'chart' => 'standard',

    /*
    |--------------------------------------------------------------------------
    | Reconcilers
    |--------------------------------------------------------------------------
    |
    | Classes implementing Alimrun\Ledger\Contracts\Reconciler, run by
    | `php artisan ledger:reconcile`. Use them to compare legacy balances
    | (wallet columns, bank statements) with the ledger.
    |
    */

    'reconcilers' => [
        // App\Ledger\CustomerWalletReconciler::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | JSON API
    |--------------------------------------------------------------------------
    |
    | Optional read and posting endpoints for an accounting UI. Reads need
    | the "view" gate, writes the "manage" gate. If your app defines neither,
    | both are allowed only in the local environment.
    |
    */

    'routes' => [
        'enabled' => env('LEDGER_ROUTES', false),
        'prefix' => 'api/ledger',
        'middleware' => ['api', 'auth'],
        'gates' => [
            'view' => 'viewLedger',
            'manage' => 'manageLedger',
        ],
    ],

];
