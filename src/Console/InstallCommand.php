<?php

namespace Alimrun\Ledger\Console;

use Alimrun\Ledger\Services\AccountService;
use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'ledger:install {--chart= : Chart preset (standard, marketplace) or path to a PHP chart file} {--no-migrate : Skip running migrations}';

    protected $description = 'Run the ledger migrations and seed the chart of accounts';

    public function handle(AccountService $accounts): int
    {
        if (! $this->option('no-migrate')) {
            $this->call('migrate', ['--force' => true]);
        }

        $chart = $this->option('chart') ?: config('ledger.chart', 'standard');
        $created = $accounts->seed($chart);

        $this->components->info("Chart [{$chart}] seeded: {$created} account(s) created.");

        return self::SUCCESS;
    }
}
