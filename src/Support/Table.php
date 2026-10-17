<?php

namespace Alimrun\Ledger\Support;

final class Table
{
    public static function name(string $table): string
    {
        return config('ledger.database.table_prefix', 'ledger_').$table;
    }
}
