<?php

namespace Alimrun\Ledger\Console;

use Alimrun\Ledger\LedgerManager;
use Illuminate\Console\Command;

class ReopenPeriodCommand extends Command
{
    protected $signature = 'ledger:reopen-period {period : Month to reopen, YYYY-MM}';

    protected $description = 'Reopen a closed month and reverse its closing journal';

    public function handle(LedgerManager $ledger): int
    {
        $ledger->reopenPeriod($this->argument('period'));

        $this->components->info("Period {$this->argument('period')} reopened.");

        return self::SUCCESS;
    }
}
