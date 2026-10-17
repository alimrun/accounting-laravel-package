<?php

namespace Alimrun\Ledger\Events;

use Alimrun\Ledger\Models\Journal;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Fired once the journal's transaction commits. Listen to it to refresh
 * wallet projections, send ERP webhooks or bust report caches.
 */
class JournalPosted implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly Journal $journal) {}
}
