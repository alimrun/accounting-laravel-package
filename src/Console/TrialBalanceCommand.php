<?php

namespace Alimrun\Ledger\Console;

use Alimrun\Ledger\LedgerManager;
use Illuminate\Console\Command;

class TrialBalanceCommand extends Command
{
    protected $signature = 'ledger:trial-balance {--as-of= : Date to report at} {--from= : Only activity from this date} {--segment= : Only this segment}';

    protected $description = 'Print the trial balance';

    public function handle(LedgerManager $ledger): int
    {
        $report = $ledger->trialBalance($this->option('as-of'), $this->option('segment'), $this->option('from'));

        $this->table(
            ['Code', 'Account', 'Type', 'Debit', 'Credit'],
            array_map(fn ($row) => [$row['code'], $row['name'], $row['type'], $row['balance_debit'], $row['balance_credit']], $report->rows),
        );

        $this->line(sprintf('Totals: debit %s, credit %s', $report->totalDebit, $report->totalCredit));

        if (! $report->isBalanced()) {
            $this->components->error('The trial balance does not balance.');

            return self::FAILURE;
        }

        $this->components->info('Balanced.');

        return self::SUCCESS;
    }
}
