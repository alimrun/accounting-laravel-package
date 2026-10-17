<?php

namespace Alimrun\Ledger\Events;

use Alimrun\Ledger\Models\Journal;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class JournalReversed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly Journal $original,
        public readonly Journal $reversal,
    ) {}
}
