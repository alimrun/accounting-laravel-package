<?php

namespace Alimrun\Ledger\Console;

use Alimrun\Ledger\LedgerManager;
use Illuminate\Console\Command;

class ReconcileCommand extends Command
{
    protected $signature = 'ledger:reconcile';

    protected $description = 'Compare external balances with the ledger using the configured reconcilers';

    public function handle(LedgerManager $ledger): int
    {
        $summary = $ledger->reconcile();

        if ($summary === []) {
            $this->components->warn('No reconcilers are configured (ledger.reconcilers).');

            return self::SUCCESS;
        }

        $this->table(['Reconciler', 'Checked', 'Differences'], collect($summary)->map(fn ($s, $name) => [$name, $s['checked'], $s['differences']])->values()->all());

        return collect($summary)->sum('differences') === 0 ? self::SUCCESS : self::FAILURE;
    }
}
