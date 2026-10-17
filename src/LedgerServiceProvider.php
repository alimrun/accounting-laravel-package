<?php

namespace Alimrun\Ledger;

use Alimrun\Ledger\Services\AccountService;
use Alimrun\Ledger\Services\BalanceService;
use Alimrun\Ledger\Services\IntegrityChecker;
use Alimrun\Ledger\Services\JournalPoster;
use Alimrun\Ledger\Services\NumberGenerator;
use Alimrun\Ledger\Services\PeriodService;
use Alimrun\Ledger\Services\ReconciliationService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class LedgerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ledger.php', 'ledger');

        foreach ([
            AccountService::class,
            BalanceService::class,
            PeriodService::class,
            NumberGenerator::class,
            JournalPoster::class,
            IntegrityChecker::class,
            ReconciliationService::class,
            LedgerManager::class,
        ] as $service) {
            $this->app->scoped($service);
        }
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/ledger.php' => config_path('ledger.php'),
            ], 'ledger-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'ledger-migrations');

            $this->publishes([
                __DIR__.'/../resources/charts' => base_path('ledger/charts'),
            ], 'ledger-charts');

            $this->publishes([
                __DIR__.'/../stubs/CLAUDE.ledger.md' => base_path('.claude/ledger.md'),
            ], 'ledger-claude');

            $this->commands([
                Console\InstallCommand::class,
                Console\SeedChartCommand::class,
                Console\TrialBalanceCommand::class,
                Console\VerifyCommand::class,
                Console\ReconcileCommand::class,
                Console\ClosePeriodCommand::class,
                Console\ReopenPeriodCommand::class,
                Console\RebuildBalancesCommand::class,
            ]);
        }

        if ($this->app['config']->get('ledger.database.run_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        $this->registerGates();
        $this->registerRoutes();
    }

    protected function registerGates(): void
    {
        // Allowed only locally unless the application defines these gates.
        foreach ($this->app['config']->get('ledger.routes.gates', []) as $ability) {
            if (! Gate::has($ability)) {
                Gate::define($ability, fn ($user = null) => $this->app->environment('local'));
            }
        }
    }

    protected function registerRoutes(): void
    {
        if (! $this->app['config']->get('ledger.routes.enabled', false) || $this->app->routesAreCached()) {
            return;
        }

        Route::prefix($this->app['config']->get('ledger.routes.prefix', 'api/ledger'))
            ->middleware($this->app['config']->get('ledger.routes.middleware', ['api', 'auth']))
            ->as('ledger.')
            ->group(__DIR__.'/../routes/api.php');
    }
}
