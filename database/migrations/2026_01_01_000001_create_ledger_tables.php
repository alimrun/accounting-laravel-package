<?php

use Alimrun\Ledger\Support\Table;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Every index, unique key and foreign key is named explicitly through
 * Table::key(), so no generated identifier exceeds MySQL's 64-character limit
 * whatever table prefix is configured.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('ledger.database.connection');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        $schema->create(Table::name('accounts'), function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique(Table::key('accounts', 'code_unique'));
            $table->string('name');
            $table->string('type', 16)->index(Table::key('accounts', 'type_index'));
            $table->string('normal_side', 6);
            $table->foreignId('parent_id')->nullable()
                ->constrained(Table::name('accounts'), 'id', Table::key('accounts', 'parent_foreign'))
                ->restrictOnDelete();
            $table->string('role', 32)->nullable()->unique(Table::key('accounts', 'role_unique'));
            $table->string('party_types')->nullable();
            $table->boolean('is_group')->default(false);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->char('currency', 3)->nullable();
            $table->text('description')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        $schema->create(Table::name('journals'), function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->nullable()->unique(Table::key('journals', 'number_unique'));
            $table->string('event', 100)->index(Table::key('journals', 'event_index'));
            $table->string('idempotency_key', 191)->unique(Table::key('journals', 'key_unique'));
            $table->char('payload_hash', 64);
            $table->string('source_type', 191)->nullable();
            $table->string('source_id', 64)->nullable();
            $table->dateTime('occurred_at')->index(Table::key('journals', 'occurred_index'));
            $table->char('period', 7)->index(Table::key('journals', 'period_index'));
            $table->string('segment', 64)->nullable()->index(Table::key('journals', 'segment_index'));
            $table->char('currency', 3);
            $table->decimal('exchange_rate', 30, 12)->default(1);
            $table->string('origin', 16)->default('live')->index(Table::key('journals', 'origin_index'));
            $table->string('description', 500)->nullable();
            $table->string('payment_method', 64)->nullable();
            $table->string('transaction_ref', 191)->nullable()->index(Table::key('journals', 'txref_index'));
            $table->foreignId('reversal_of_id')->nullable()
                ->unique(Table::key('journals', 'reversal_unique'))
                ->constrained(Table::name('journals'), 'id', Table::key('journals', 'reversal_foreign'))
                ->restrictOnDelete();
            $table->string('created_by_type', 191)->nullable();
            $table->string('created_by_id', 64)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['source_type', 'source_id'], Table::key('journals', 'source_index'));
        });

        $schema->create(Table::name('entries'), function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_id')
                ->constrained(Table::name('journals'), 'id', Table::key('entries', 'journal_foreign'))
                ->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('account_id')
                ->constrained(Table::name('accounts'), 'id', Table::key('entries', 'account_foreign'))
                ->restrictOnDelete();
            $table->string('party_type', 191)->nullable();
            $table->string('party_id', 64)->nullable();
            $table->decimal('debit', 30, 12)->default(0);
            $table->decimal('credit', 30, 12)->default(0);
            $table->string('tag', 64)->nullable()->index(Table::key('entries', 'tag_index'));
            $table->string('segment', 64)->nullable();
            $table->string('reference_type', 191)->nullable();
            $table->string('reference_id', 64)->nullable();
            $table->string('memo', 500)->nullable();
            $table->json('dimensions')->nullable();
            $table->dateTime('occurred_at');
            $table->char('period', 7);
            $table->string('origin', 16)->default('live');

            $table->index(['account_id', 'occurred_at'], Table::key('entries', 'account_date_index'));
            $table->index(['party_type', 'party_id', 'account_id'], Table::key('entries', 'party_index'));
            $table->index(['reference_type', 'reference_id'], Table::key('entries', 'reference_index'));
            $table->index(['segment', 'account_id', 'occurred_at'], Table::key('entries', 'segment_index'));
            $table->index(['period', 'account_id'], Table::key('entries', 'period_index'));
        });

        $driver = DB::connection($this->getConnection())->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            DB::connection($this->getConnection())->statement(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s CHECK ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0))',
                Table::name('entries'),
                Table::key('entries', 'one_side_check'),
            ));
        }

        $schema->create(Table::name('balances'), function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')
                ->constrained(Table::name('accounts'), 'id', Table::key('balances', 'account_foreign'))
                ->restrictOnDelete();
            $table->string('party_type', 191)->default('');
            $table->string('party_id', 64)->default('');
            $table->decimal('debit_total', 30, 12)->default(0);
            $table->decimal('credit_total', 30, 12)->default(0);
            $table->unsignedBigInteger('last_entry_id')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['account_id', 'party_type', 'party_id'], Table::key('balances', 'row_unique'));
            $table->index(['party_type', 'party_id'], Table::key('balances', 'party_index'));
        });

        $schema->create(Table::name('periods'), function (Blueprint $table) {
            $table->id();
            $table->char('period', 7)->unique(Table::key('periods', 'period_unique'));
            $table->string('status', 16)->default('open');
            $table->unsignedInteger('close_count')->default(0);
            $table->foreignId('closing_journal_id')->nullable()
                ->constrained(Table::name('journals'), 'id', Table::key('periods', 'journal_foreign'))
                ->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->string('closed_by_type', 191)->nullable();
            $table->string('closed_by_id', 64)->nullable();
            $table->timestamps();
        });

        $schema->create(Table::name('sequences'), function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique(Table::key('sequences', 'name_unique'));
            $table->unsignedBigInteger('value')->default(0);
            $table->timestamp('updated_at')->nullable();
        });

        $schema->create(Table::name('reconciliation_issues'), function (Blueprint $table) {
            $table->id();
            $table->string('reconciler', 100)->index(Table::key('reconciliation_issues', 'reconciler_index'));
            $table->string('subject', 191)->nullable();
            $table->string('accounts')->nullable();
            $table->string('party_type', 191)->nullable();
            $table->string('party_id', 64)->nullable();
            $table->decimal('expected', 30, 12);
            $table->decimal('actual', 30, 12);
            $table->decimal('difference', 30, 12);
            $table->json('meta')->nullable();
            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable()->index(Table::key('reconciliation_issues', 'resolved_index'));
            $table->string('resolution_note', 500)->nullable();
            $table->timestamps();

            $table->index(['reconciler', 'party_type', 'party_id'], Table::key('reconciliation_issues', 'party_index'));
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        $schema->dropIfExists(Table::name('reconciliation_issues'));
        $schema->dropIfExists(Table::name('sequences'));
        $schema->dropIfExists(Table::name('periods'));
        $schema->dropIfExists(Table::name('balances'));
        $schema->dropIfExists(Table::name('entries'));
        $schema->dropIfExists(Table::name('journals'));
        $schema->dropIfExists(Table::name('accounts'));
    }
};
