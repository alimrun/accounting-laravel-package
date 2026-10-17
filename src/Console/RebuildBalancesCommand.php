<?php

namespace Alimrun\Ledger\Console;

use Alimrun\Ledger\LedgerManager;
use Illuminate\Console\Command;

class RebuildBalancesCommand extends Command
{
    protected $signature = 'ledger:rebuild-balances {--force : Run without confirmation}';

    protected $description = 'Recompute every running balance from the entries (run while posting is paused)';

    public function handle(LedgerManager $ledger): int
    {
        if (! $this->option('force') && ! $this->confirm('Rebuild all ledger balances? Pause posting first.')) {
            return self::FAILURE;
        }

        $this->components->info($ledger->rebuildBalances().' balance row(s) rebuilt.');

        return self::SUCCESS;
    }
}
