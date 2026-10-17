<?php

namespace Alimrun\Ledger\Services;

use Alimrun\Ledger\Enums\JournalOrigin;
use Alimrun\Ledger\Events\JournalPosted;
use Alimrun\Ledger\Exceptions\IdempotencyConflictException;
use Alimrun\Ledger\Exceptions\InvalidJournalException;
use Alimrun\Ledger\Exceptions\PartyMismatchException;
use Alimrun\Ledger\Exceptions\UnbalancedJournalException;
use Alimrun\Ledger\Models\Entry;
use Alimrun\Ledger\Models\Journal;
use Alimrun\Ledger\Posting\JournalDraft;
use Alimrun\Ledger\Posting\Line;
use Alimrun\Ledger\Support\Amount;
use Alimrun\Ledger\Support\Events;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Validates and writes journals. Every ledger write goes through here.
 */
class JournalPoster
{
    public function __construct(
        protected Repository $config,
        protected AccountService $accounts,
        protected BalanceService $balances,
        protected PeriodService $periods,
        protected NumberGenerator $numbers,
    ) {}

    /**
     * @param  callable(callable): mixed  $transaction  runs a callback in a ledger transaction
     */
    public function post(JournalDraft $draft, callable $transaction): Journal
    {
        if (trim($draft->event) === '') {
            throw new InvalidJournalException('A journal needs an event name.');
        }

        $key = $draft->idempotencyKey;

        if ($key === null || $key === '') {
            if ($this->config->get('ledger.idempotency.required', true)) {
                throw new InvalidJournalException("Journal [{$draft->event}] needs an idempotency key, such as \"order:{id}:delivered\".");
            }

            $key = (string) Str::ulid();
        }

        $lines = $this->balance($draft->event, $this->resolve($draft));
        $hash = $this->hash($draft->event, $lines);

        if ($existing = $this->find($key)) {
            return $this->replay($existing, $hash);
        }

        try {
            return $transaction(fn () => $this->write($draft, $key, $hash, $lines));
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent request posted the same key first.
            if ($existing = $this->find($key)) {
                return $this->replay($existing, $hash);
            }

            throw $e;
        }
    }

    public function find(string $idempotencyKey): ?Journal
    {
        return Journal::query()->where('idempotency_key', $idempotencyKey)->first();
    }

    /**
     * @param  list<Line>  $lines
     */
    protected function write(JournalDraft $draft, string $key, string $hash, array $lines): Journal
    {
        $occurredAt = Carbon::instance($draft->occurredAt ?? now())->startOfSecond();
        $period = $this->periods->for($occurredAt);

        $this->periods->assertOpen($period);

        $journal = Journal::query()->create([
            'event' => $draft->event,
            'idempotency_key' => $key,
            'payload_hash' => $hash,
            'source_type' => $draft->source?->type,
            'source_id' => $draft->source?->id,
            'occurred_at' => $occurredAt,
            'period' => $period,
            'segment' => $draft->segment,
            'currency' => $draft->currency ?? $this->config->get('ledger.currency', 'USD'),
            'exchange_rate' => $draft->exchangeRate,
            'origin' => $draft->origin,
            'description' => $draft->description,
            'payment_method' => $draft->paymentMethod,
            'transaction_ref' => $draft->transactionRef,
            'reversal_of_id' => $draft->reversalOfId,
            'created_by_type' => $draft->createdBy?->type,
            'created_by_id' => $draft->createdBy?->id,
            'meta' => $draft->meta ?: null,
        ]);

        $entries = [];

        foreach (array_values($lines) as $i => $line) {
            $entries[] = Entry::query()->create([
                'journal_id' => $journal->id,
                'line_no' => $i + 1,
                'account_id' => $line->account->id,
                'party_type' => $line->party?->type,
                'party_id' => $line->party?->id,
                'debit' => (string) $line->debit,
                'credit' => (string) $line->credit,
                'tag' => $line->tag,
                'segment' => $line->segment,
                'reference_type' => $line->reference?->type,
                'reference_id' => $line->reference?->id,
                'memo' => $line->memo,
                'dimensions' => $line->dimensions ?: null,
                'occurred_at' => $occurredAt,
                'period' => $period,
                'origin' => $draft->origin,
            ]);
        }

        $this->balances->apply($entries);
        $this->numbers->assign($journal);

        $journal->setRelation('entries', collect($entries));

        Events::dispatch(new JournalPosted($journal));

        return $journal;
    }

    /**
     * Resolves accounts and checks each line against its account.
     *
     * @return list<Line>
     */
    protected function resolve(JournalDraft $draft): array
    {
        // Reversals and period closes must go through even when an account has
        // since been deactivated or its party rules changed.
        $lenient = $draft->reversalOfId !== null || $draft->origin === JournalOrigin::Closing;
        $strictParties = (bool) $this->config->get('ledger.strict_parties', true);
        $resolved = [];

        foreach ($draft->lines as $line) {
            $account = $this->accounts->find($line->account);

            if ($account->is_group) {
                throw new InvalidJournalException("Account [{$account->code}] is a group account; post to one of its children.");
            }

            if (! $account->is_active && ! $lenient) {
                throw new InvalidJournalException("Account [{$account->code}] is inactive.");
            }

            if ($strictParties && ! $lenient && ! $account->acceptsParty($line->party)) {
                throw new PartyMismatchException(match (true) {
                    $line->party === null => "Account [{$account->code}] needs a party ({$account->party_types}).",
                    ! $account->requiresParty() => "Account [{$account->code}] does not take a party; got [{$line->party->key()}].",
                    default => "Account [{$account->code}] takes {$account->party_types} parties; got [{$line->party->type}].",
                });
            }

            $oneSided = $line->debit->isPositive() !== $line->credit->isPositive();

            if (! $oneSided || $line->debit->isNegative() || $line->credit->isNegative()) {
                throw new InvalidJournalException("Each line needs exactly one positive side (account [{$account->code}]).");
            }

            $resolved[] = $line->withAccount($account)->withSegment($line->segment ?? $draft->segment);
        }

        return $resolved;
    }

    /**
     * Checks debits equal credits. A residual within the tolerance goes to the
     * rounding account; anything larger is refused.
     *
     * @param  list<Line>  $lines
     * @return list<Line>
     */
    protected function balance(string $event, array $lines): array
    {
        $debit = Amount::zero();
        $credit = Amount::zero();

        foreach ($lines as $line) {
            $debit = $debit->plus($line->debit);
            $credit = $credit->plus($line->credit);
        }

        $difference = $debit->minus($credit);

        if (! $difference->isZero()) {
            $tolerance = Amount::of($this->config->get('ledger.rounding.tolerance', '0.000010'));
            $rounding = $this->accounts->findRoleOrNull('rounding');

            if ($rounding === null || $difference->abs()->isGreaterThan($tolerance)) {
                throw new UnbalancedJournalException((string) $debit, (string) $credit, $event);
            }

            $lines[] = new Line(
                account: $rounding,
                debit: $difference->isNegative() ? $difference->abs() : Amount::zero(),
                credit: $difference->isPositive() ? $difference : Amount::zero(),
                tag: 'rounding',
                segment: $lines[0]->segment ?? null,
            );
        }

        if (count($lines) < 2) {
            throw new InvalidJournalException("Journal [{$event}] needs at least two lines.");
        }

        return $lines;
    }

    /**
     * Fingerprint of what the journal moves, used to spot a key reused for
     * different money.
     *
     * @param  list<Line>  $lines
     */
    protected function hash(string $event, array $lines): string
    {
        $parts = array_map(fn (Line $line) => implode('|', [
            $line->accountCode(),
            $line->party?->key() ?? '',
            (string) $line->debit,
            (string) $line->credit,
            $line->tag ?? '',
        ]), $lines);

        sort($parts);

        return hash('sha256', $event."\n".implode("\n", $parts));
    }

    protected function replay(Journal $existing, string $hash): Journal
    {
        if ($this->config->get('ledger.idempotency.strict', true) && ! hash_equals($existing->payload_hash, $hash)) {
            throw new IdempotencyConflictException($existing->idempotency_key);
        }

        return $existing;
    }
}
