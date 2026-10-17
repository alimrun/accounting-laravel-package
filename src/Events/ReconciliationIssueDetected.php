<?php

namespace Alimrun\Ledger\Events;

use Alimrun\Ledger\Models\ReconciliationIssue;

class ReconciliationIssueDetected
{
    public function __construct(public readonly ReconciliationIssue $issue) {}
}
