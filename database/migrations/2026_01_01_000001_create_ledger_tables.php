<?php

use Alimrun\Ledger\Support\Table;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('type', 16)->index();
            $table->string('normal_side', 6);
            $table->foreignId('parent_id')->nullable()->constrained(Table::name('accounts'))->restrictOnDelete();
            $table->string('role', 32)->nullable()->unique();
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
            $table->string('number', 40)->nullable()->unique();
            $table->string('event', 100)->index();
            $table->string('idempotency_key', 191)->unique();
            $table->char('payload_hash', 64);
            $table->string('source_type', 191)->nullable();
            $table->string('source_id', 64)->nullable();
            $table->dateTime('occurred_at')->index();
            $table->char('period', 7)->index();
            $table->string('segment', 64)->nullable()->index();
            $table->char('currency', 3);
            $table->decimal('exchange_rate', 30, 12)->default(1);
            $table->string('origin', 16)->default('live')->index();
            $table->string('description', 500)->nullable();
            $table->string('payment_method', 64)->nullable();
            $table->string('transaction_ref', 191)->nullable()->index();
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained(Table::name('journals'))->restrictOnDelete();
            $table->string('created_by_type', 191)->nullable();
            $table->string('created_by_id', 64)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['source_type', 'source_id']);
        });

        $schema->create(Table::name('entries'), function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_id')->constrained(Table::name('journals'))->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('account_id')->constrained(Table::name('accounts'))->restrictOnDelete();
            $table->string('party_type', 191)->nullable();
            $table->string('party_id', 64)->nullable();
            $table->decimal('debit', 30, 12)->default(0);
            $table->decimal('credit', 30, 12)->default(0);
            $table->string('tag', 64)->nullable()->index();
            $table->string('segment', 64)->nullable();
            $table->string('reference_type', 191)->nullable();
            $table->string('reference_id', 64)->nullable();
            $table->string('memo', 500)->nullable();
            $table->json('dimensions')->nullable();
            $table->dateTime('occurred_at');
            $table->char('period', 7);
            $table->string('origin', 16)->default('live');

            $table->index(['account_id', 'occurred_at']);
            $table->index(['party_type', 'party_id', 'account_id']);
            $table->index(['reference_type', 'reference_id']);
            $table->index(['segment', 'account_id', 'occurred_at']);
            $table->index(['period', 'account_id']);
        });

        $driver = DB::connection($this->getConnection())->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            DB::connection($this->getConnection())->statement(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s CHECK ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0))',
                Table::name('entries'),
                Table::name('entries_one_side_check'),
            ));
        }

        $schema->create(Table::name('balances'), function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained(Table::name('accounts'))->restrictOnDelete();
            $table->string('party_type', 191)->default('');
            $table->string('party_id', 64)->default('');
            $table->decimal('debit_total', 30, 12)->default(0);
            $table->decimal('credit_total', 30, 12)->default(0);
            $table->unsignedBigInteger('last_entry_id')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['account_id', 'party_type', 'party_id']);
            $table->index(['party_type', 'party_id']);
        });

        $schema->create(Table::name('periods'), function (Blueprint $table) {
            $table->id();
            $table->char('period', 7)->unique();
            $table->string('status', 16)->default('open');
            $table->unsignedInteger('close_count')->default(0);
            $table->foreignId('closing_journal_id')->nullable()->constrained(Table::name('journals'))->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->string('closed_by_type', 191)->nullable();
            $table->string('closed_by_id', 64)->nullable();
            $table->timestamps();
        });

        $schema->create(Table::name('sequences'), function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->unsignedBigInteger('value')->default(0);
            $table->timestamp('updated_at')->nullable();
        });

        $schema->create(Table::name('reconciliation_issues'), function (Blueprint $table) {
            $table->id();
            $table->string('reconciler', 100)->index();
            $table->string('subject', 191)->nullable();
            $table->string('accounts')->nullable();
            $table->string('party_type', 191)->nullable();
            $table->string('party_id', 64)->nullable();
            $table->decimal('expected', 30, 12);
            $table->decimal('actual', 30, 12);
            $table->decimal('difference', 30, 12);
            $table->json('meta')->nullable();
            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable()->index();
            $table->string('resolution_note', 500)->nullable();
            $table->timestamps();

            $table->index(['reconciler', 'subject', 'party_type', 'party_id']);
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
