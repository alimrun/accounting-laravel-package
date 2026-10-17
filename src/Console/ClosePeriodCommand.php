<?php

namespace Alimrun\Ledger\Console;

use Alimrun\Ledger\LedgerManager;
use Illuminate\Console\Command;

class ClosePeriodCommand extends Command
{
    protected $signature = 'ledger:close-period {period : Month to close, YYYY-MM}';

    protected $description = 'Close a month into retained earnings and refuse further postings to it';

    public function handle(LedgerManager $ledger): int
    {
        $journal = $ledger->closePeriod($this->argument('period'));

        $this->components->info(sprintf(
            'Period %s closed%s.',
            $this->argument('period'),
            $journal ? " with closing journal {$journal->number}" : ' (no income or expense activity)',
        ));

        return self::SUCCESS;
    }
}
