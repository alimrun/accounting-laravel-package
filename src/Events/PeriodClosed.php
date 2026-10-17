<?php

namespace Alimrun\Ledger\Events;

use Alimrun\Ledger\Models\Journal;
use Alimrun\Ledger\Models\Period;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class PeriodClosed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly Period $period,
        public readonly ?Journal $closingJournal,
    ) {}
}
