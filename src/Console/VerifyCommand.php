<?php

namespace Alimrun\Ledger\Console;

use Alimrun\Ledger\LedgerManager;
use Illuminate\Console\Command;

class VerifyCommand extends Command
{
    protected $signature = 'ledger:verify';

    protected $description = 'Check that every journal balances and every running balance matches its entries';

    public function handle(LedgerManager $ledger): int
    {
        $problems = $ledger->verify();

        if ($problems === []) {
            $this->components->info('The books are consistent.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->components->error($problem);
        }

        return self::FAILURE;
    }
}
