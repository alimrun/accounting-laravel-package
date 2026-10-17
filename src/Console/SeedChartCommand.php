<?php

namespace Alimrun\Ledger\Console;

use Alimrun\Ledger\Services\AccountService;
use Illuminate\Console\Command;

class SeedChartCommand extends Command
{
    protected $signature = 'ledger:seed-chart {chart? : Chart preset (standard, marketplace) or path to a PHP chart file}';

    protected $description = 'Create any missing accounts from a chart of accounts';

    public function handle(AccountService $accounts): int
    {
        $chart = $this->argument('chart') ?: config('ledger.chart', 'standard');
        $created = $accounts->seed($chart);

        $this->components->info("{$created} account(s) created from [{$chart}]. Existing codes were left as they are.");

        return self::SUCCESS;
    }
}
