<?php

use Alimrun\Ledger\Support\Table;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Refuses UPDATE and DELETE on journals and entries at the database level,
 * so raw queries cannot rewrite the books either. A journal may be updated
 * once while its number is still null: the poster assigns the number last.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('ledger.database.connection');
    }

    public function up(): void
    {
        if (! config('ledger.database.immutable_triggers')) {
            return;
        }

        $db = DB::connection($this->getConnection());
        $journals = Table::name('journals');
        $entries = Table::name('entries');

        match ($db->getDriverName()) {
            'mysql', 'mariadb' => $this->mysql($db, $journals, $entries),
            'pgsql' => $this->pgsql($db, $journals, $entries),
            'sqlite' => $this->sqlite($db, $journals, $entries),
            default => null,
        };
    }

    public function down(): void
    {
        $db = DB::connection($this->getConnection());
        $journals = Table::name('journals');
        $entries = Table::name('entries');

        if ($db->getDriverName() === 'pgsql') {
            $db->unprepared("DROP TRIGGER IF EXISTS {$this->trigger('journals', 'immutable')} ON {$journals}");
            $db->unprepared("DROP TRIGGER IF EXISTS {$this->trigger('entries', 'immutable')} ON {$entries}");
            $db->unprepared('DROP FUNCTION IF EXISTS ledger_refuse_change()');
            $db->unprepared('DROP FUNCTION IF EXISTS ledger_refuse_journal_change()');

            return;
        }

        foreach ([['journals', 'no_update'], ['journals', 'no_delete'], ['entries', 'no_update'], ['entries', 'no_delete']] as [$table, $suffix]) {
            $trigger = $this->trigger($table, $suffix);
            $db->unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }

    private function trigger(string $table, string $suffix): string
    {
        return Table::key($table, $suffix);
    }

    private function mysql($db, string $journals, string $entries): void
    {
        $db->unprepared("CREATE TRIGGER {$this->trigger('journals', 'no_update')} BEFORE UPDATE ON {$journals} FOR EACH ROW BEGIN IF OLD.number IS NOT NULL OR NEW.idempotency_key <> OLD.idempotency_key OR NEW.payload_hash <> OLD.payload_hash THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger journals are immutable'; END IF; END");
        $db->unprepared("CREATE TRIGGER {$this->trigger('journals', 'no_delete')} BEFORE DELETE ON {$journals} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger journals are immutable'");
        $db->unprepared("CREATE TRIGGER {$this->trigger('entries', 'no_update')} BEFORE UPDATE ON {$entries} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger entries are immutable'");
        $db->unprepared("CREATE TRIGGER {$this->trigger('entries', 'no_delete')} BEFORE DELETE ON {$entries} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger entries are immutable'");
    }

    private function pgsql($db, string $journals, string $entries): void
    {
        $db->unprepared("CREATE OR REPLACE FUNCTION ledger_refuse_change() RETURNS trigger AS \$\$ BEGIN RAISE EXCEPTION 'Ledger entries are immutable'; END; \$\$ LANGUAGE plpgsql");
        $db->unprepared("CREATE OR REPLACE FUNCTION ledger_refuse_journal_change() RETURNS trigger AS \$\$ BEGIN IF TG_OP = 'DELETE' OR OLD.number IS NOT NULL OR NEW.idempotency_key <> OLD.idempotency_key OR NEW.payload_hash <> OLD.payload_hash THEN RAISE EXCEPTION 'Ledger journals are immutable'; END IF; RETURN NEW; END; \$\$ LANGUAGE plpgsql");
        $db->unprepared("CREATE TRIGGER {$this->trigger('journals', 'immutable')} BEFORE UPDATE OR DELETE ON {$journals} FOR EACH ROW EXECUTE FUNCTION ledger_refuse_journal_change()");
        $db->unprepared("CREATE TRIGGER {$this->trigger('entries', 'immutable')} BEFORE UPDATE OR DELETE ON {$entries} FOR EACH ROW EXECUTE FUNCTION ledger_refuse_change()");
    }

    private function sqlite($db, string $journals, string $entries): void
    {
        $db->unprepared("CREATE TRIGGER {$this->trigger('journals', 'no_update')} BEFORE UPDATE ON {$journals} WHEN OLD.number IS NOT NULL OR NEW.idempotency_key <> OLD.idempotency_key OR NEW.payload_hash <> OLD.payload_hash BEGIN SELECT RAISE(ABORT, 'Ledger journals are immutable'); END");
        $db->unprepared("CREATE TRIGGER {$this->trigger('journals', 'no_delete')} BEFORE DELETE ON {$journals} BEGIN SELECT RAISE(ABORT, 'Ledger journals are immutable'); END");
        $db->unprepared("CREATE TRIGGER {$this->trigger('entries', 'no_update')} BEFORE UPDATE ON {$entries} BEGIN SELECT RAISE(ABORT, 'Ledger entries are immutable'); END");
        $db->unprepared("CREATE TRIGGER {$this->trigger('entries', 'no_delete')} BEFORE DELETE ON {$entries} BEGIN SELECT RAISE(ABORT, 'Ledger entries are immutable'); END");
    }
};
