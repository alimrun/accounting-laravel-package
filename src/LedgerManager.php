<?php

namespace Alimrun\Ledger;

use Alimrun\Ledger\Enums\JournalOrigin;
use Alimrun\Ledger\Enums\NormalSide;
use Alimrun\Ledger\Events\JournalReversed;
use Alimrun\Ledger\Models\Account;
use Alimrun\Ledger\Models\Journal;
use Alimrun\Ledger\Posting\JournalBuilder;
use Alimrun\Ledger\Posting\Line;
use Alimrun\Ledger\Posting\Posting;
use Alimrun\Ledger\Reports\AccountStatement;
use Alimrun\Ledger\Reports\BalanceSheet;
use Alimrun\Ledger\Reports\IncomeStatement;
use Alimrun\Ledger\Reports\PartyBalances;
use Alimrun\Ledger\Reports\TrialBalance;
use Alimrun\Ledger\Services\AccountService;
use Alimrun\Ledger\Services\BalanceService;
use Alimrun\Ledger\Services\IntegrityChecker;
use Alimrun\Ledger\Services\JournalPoster;
use Alimrun\Ledger\Services\PeriodService;
use Alimrun\Ledger\Services\ReconciliationService;
use Alimrun\Ledger\Support\Amount;
use Alimrun\Ledger\Support\Events;
use Alimrun\Ledger\Support\Ref;
use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Entry point behind the Ledger facade.
 */
class LedgerManager
{
    protected ?bool $enabled = null;

    public function __construct(
        protected Repository $config,
        protected DatabaseManager $db,
        protected AccountService $accounts,
        protected BalanceService $balances,
        protected PeriodService $periods,
        protected JournalPoster $poster,
        protected IntegrityChecker $integrity,
        protected ReconciliationService $reconciliation,
    ) {}

    // ---------------------------------------------------------------------
    // Posting
    // ---------------------------------------------------------------------

    public function journal(string $event): JournalBuilder
    {
        return new JournalBuilder($this, $event);
    }

    /**
     * Posts a built journal or a posting rule. Returns the existing journal
     * when the idempotency key was already posted, and null while the ledger
     * is disabled.
     */
    public function post(JournalBuilder|Posting $journal): ?Journal
    {
        if ($journal instanceof Posting) {
            $builder = $this->journal($journal->event())->idempotencyKey($journal->idempotencyKey());
            $journal->build($builder);
            $journal = $builder;
        }

        if (! $this->isEnabled()) {
            return null;
        }

        return $this->poster->post($journal->toDraft(), fn (Closure $callback) => $this->transaction($callback));
    }

    /**
     * Posts the mirror image of a journal. Reversing twice returns the first
     * reversal.
     */
    public function reverse(
        Journal|int $journal,
        ?string $reason = null,
        CarbonInterface|string|null $occurredAt = null,
        Model|Ref|array|null $reversedBy = null,
    ): ?Journal {
        $original = $journal instanceof Journal ? $journal : Journal::query()->findOrFail($journal);
        $original->loadMissing('entries');

        $builder = $this->journal($original->event.'.reversal')
            ->idempotencyKey('reversal:'.$original->id)
            ->reversalOf($original)
            ->origin($original->origin === JournalOrigin::Closing ? JournalOrigin::Closing : JournalOrigin::Live)
            ->occurredAt($occurredAt ?? now())
            ->segment($original->segment)
            ->currency($original->currency, $original->exchange_rate)
            ->description($reason ?? 'Reversal of '.$original->number)
            ->createdBy($reversedBy)
            ->meta(['reversal_of' => $original->number]);

        if ($original->source_type) {
            $builder->source(new Ref($original->source_type, $original->source_id));
        }

        foreach ($original->entries as $entry) {
            $builder->line(new Line(
                account: (int) $entry->account_id,
                debit: Amount::of($entry->credit),
                credit: Amount::of($entry->debit),
                party: $entry->party(),
                tag: $entry->tag,
                segment: $entry->segment,
                reference: $entry->reference(),
                memo: $entry->memo,
                dimensions: $entry->dimensions ?? [],
            ));
        }

        $reversal = $this->post($builder);

        if ($reversal && $reversal->wasRecentlyCreated) {
            Events::dispatch(new JournalReversed($original, $reversal));
        }

        return $reversal;
    }

    /**
     * Posts a cut-over balance for an account (and party) against opening
     * balance equity. The amount is positive on the account's normal side.
     */
    public function openingBalance(
        string|int|Account $account,
        BigNumber|int|float|string $amount,
        Model|Ref|array|null $party = null,
        CarbonInterface|string|null $asOf = null,
        ?string $segment = null,
    ): ?Journal {
        $account = $this->accounts->find($account);
        $equity = $this->accounts->findByRole('opening_balance_equity');
        $party = Ref::from($party);
        $value = Amount::of($amount);
        $onNormalSide = ! $value->isNegative();
        $value = $value->abs();
        $debitAccount = ($account->normal_side === NormalSide::Debit) === $onNormalSide;

        return $this->journal('opening.balance')
            ->idempotencyKey('opening:'.$account->code.($party ? ':'.$party->key() : '').($segment ? ':'.$segment : ''))
            ->origin(JournalOrigin::Opening)
            ->occurredAt($asOf ?? now())
            ->segment($segment)
            ->description('Opening balance '.$account->label())
            ->when($debitAccount,
                fn (JournalBuilder $j) => $j->debit($account, $value, party: $party, tag: 'opening')->credit($equity, $value, tag: 'opening'),
                fn (JournalBuilder $j) => $j->credit($account, $value, party: $party, tag: 'opening')->debit($equity, $value, tag: 'opening'),
            )
            ->post();
    }

    public function find(string $idempotencyKey): ?Journal
    {
        return $this->poster->find($idempotencyKey);
    }

    // ---------------------------------------------------------------------
    // Balances
    // ---------------------------------------------------------------------

    /**
     * Balance of one or more accounts (with their children), positive on the
     * given side, defaulting to the first account's normal side.
     *
     *     Ledger::balance('2000', $customer);                       // wallet
     *     Ledger::balance(['2100', '1100'], $seller, side: 'credit'); // withdrawable
     */
    public function balance(
        string|int|Account|array $accounts,
        Model|Ref|array|null $party = null,
        CarbonInterface|string|null $asOf = null,
        ?string $segment = null,
        NormalSide|string|null $side = null,
    ): BigDecimal {
        return $this->balances->balance(
            $this->resolveAccounts($accounts),
            $this->party($party),
            is_string($side) ? NormalSide::from($side) : $side,
            $asOf === null ? null : Carbon::parse($asOf),
            $segment,
        );
    }

    /**
     * Locks the balance row and returns its balance. Use it inside the
     * transaction that then posts, for example before paying from a wallet.
     */
    public function lockBalance(
        string|int|Account $account,
        Model|Ref|array|null $party = null,
        NormalSide|string|null $side = null,
    ): BigDecimal {
        return $this->balances->locked(
            $this->accounts->find($account),
            $this->party($party),
            is_string($side) ? NormalSide::from($side) : $side,
        );
    }

    // ---------------------------------------------------------------------
    // Reports
    // ---------------------------------------------------------------------

    public function trialBalance(CarbonInterface|string|null $asOf = null, ?string $segment = null, CarbonInterface|string|null $from = null, bool $includeZero = false): TrialBalance
    {
        return TrialBalance::generate($this->accounts, $this->date($asOf), $segment, $this->date($from), $includeZero);
    }

    public function incomeStatement(CarbonInterface|string|null $from = null, CarbonInterface|string|null $to = null, ?string $segment = null): IncomeStatement
    {
        return IncomeStatement::generate($this->accounts, $this->date($from), $this->date($to), $segment);
    }

    public function balanceSheet(CarbonInterface|string|null $asOf = null, ?string $segment = null): BalanceSheet
    {
        return BalanceSheet::generate($this->accounts, $this->date($asOf), $segment);
    }

    public function statement(
        string|int|Account|array $accounts,
        Model|Ref|array|null $party = null,
        CarbonInterface|string|null $from = null,
        CarbonInterface|string|null $to = null,
        ?string $segment = null,
        NormalSide|string|null $side = null,
        ?int $limit = null,
    ): AccountStatement {
        return AccountStatement::generate(
            $this->accounts,
            $this->balances,
            $this->resolveAccounts($accounts),
            $this->party($party),
            $this->date($from),
            $this->date($to),
            $segment,
            is_string($side) ? NormalSide::from($side) : $side,
            $limit,
        );
    }

    public function partyBalances(string|int|Account $account, CarbonInterface|string|null $asOf = null, bool $includeZero = false): PartyBalances
    {
        return PartyBalances::generate($this->accounts, $this->accounts->find($account), $this->date($asOf), $includeZero);
    }

    // ---------------------------------------------------------------------
    // Periods, integrity, reconciliation
    // ---------------------------------------------------------------------

    public function closePeriod(string $period, Model|Ref|array|null $closedBy = null): ?Journal
    {
        return $this->periods->close($period, $closedBy);
    }

    public function reopenPeriod(string $period, Model|Ref|array|null $reopenedBy = null): void
    {
        $this->periods->reopen($period, $reopenedBy);
    }

    /**
     * @return list<string> problems; empty when the books are sound
     */
    public function verify(): array
    {
        return $this->integrity->check();
    }

    /**
     * @return array<string, array{checked: int, differences: int}>
     */
    public function reconcile(?array $reconcilers = null): array
    {
        return $this->reconciliation->run($reconcilers);
    }

    public function rebuildBalances(): int
    {
        return $this->transaction(fn () => $this->balances->rebuild());
    }

    // ---------------------------------------------------------------------
    // Plumbing
    // ---------------------------------------------------------------------

    public function accounts(): AccountService
    {
        return $this->accounts;
    }

    public function account(string|int|Account $account): Account
    {
        return $this->accounts->find($account);
    }

    public function periods(): PeriodService
    {
        return $this->periods;
    }

    public function isEnabled(): bool
    {
        return $this->enabled ?? (bool) $this->config->get('ledger.enabled', true);
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function connection(): ConnectionInterface
    {
        return $this->db->connection($this->config->get('ledger.database.connection'));
    }

    /**
     * Runs the callback in a ledger transaction. Inside an open transaction
     * it nests as a savepoint, so the posting commits or rolls back with the
     * business change around it.
     */
    public function transaction(Closure $callback): mixed
    {
        $connection = $this->connection();
        $attempts = $connection->transactionLevel() === 0 ? max(1, (int) $this->config->get('ledger.posting.attempts', 3)) : 1;

        return $connection->transaction($callback, $attempts);
    }

    /**
     * @return list<Account>
     */
    protected function resolveAccounts(string|int|Account|array $accounts): array
    {
        return array_map(fn ($account) => $this->accounts->find($account), is_array($accounts) ? array_values($accounts) : [$accounts]);
    }

    protected function party(Model|Ref|array|null $party): ?Ref
    {
        return Ref::from($party);
    }

    protected function date(CarbonInterface|string|null $value): ?CarbonInterface
    {
        return $value === null ? null : Carbon::parse($value);
    }
}
