<?php

namespace Alimrun\Ledger\Contracts;

use Alimrun\Ledger\Support\Expectation;

/**
 * Compares balances kept outside the ledger (wallet columns, bank
 * statements, a legacy system) with ledger balances.
 */
interface Reconciler
{
    /**
     * Short stable name, stored on each issue.
     */
    public function name(): string;

    /**
     * @return iterable<Expectation>
     */
    public function expectations(): iterable;
}
