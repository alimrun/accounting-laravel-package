<?php

namespace Alimrun\Ledger\Support;

final class Table
{
    public static function name(string $table): string
    {
        return config('ledger.database.table_prefix', 'ledger_').$table;
    }

    /**
     * Name for an index, unique key, foreign key or constraint on a ledger
     * table. MySQL refuses identifiers over 64 characters, so long names
     * (from a long table prefix) are shortened with a stable hash suffix.
     */
    public static function key(string $table, string $suffix): string
    {
        $name = self::name($table).'_'.$suffix;

        return strlen($name) <= 64 ? $name : substr($name, 0, 55).'_'.substr(md5($name), 0, 8);
    }
}
