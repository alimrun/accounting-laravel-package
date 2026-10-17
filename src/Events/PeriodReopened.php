<?php

namespace Alimrun\Ledger\Events;

use Alimrun\Ledger\Models\Period;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class PeriodReopened implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly Period $period) {}
}
