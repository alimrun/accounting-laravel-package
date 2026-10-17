<?php

namespace Alimrun\Ledger\Models\Concerns;

use Alimrun\Ledger\Support\Table;

/**
 * Resolves the configured connection and prefixed table name.
 */
trait LedgerModel
{
    public function getConnectionName()
    {
        return config('ledger.database.connection') ?? $this->connection;
    }

    public function getTable()
    {
        return Table::name($this->ledgerTable);
    }
}
