<?php

namespace Alimrun\Ledger\Services;

use Alimrun\Ledger\Enums\AccountType;
use Alimrun\Ledger\Enums\JournalOrigin;
use Alimrun\Ledger\Enums\PeriodStatus;
use Alimrun\Ledger\Events\PeriodClosed;
use Alimrun\Ledger\Events\PeriodReopened;
use Alimrun\Ledger\Exceptions\LedgerException;
use Alimrun\Ledger\Exceptions\PeriodClosedException;
use Alimrun\Ledger\LedgerManager;
use Alimrun\Ledger\Models\Entry;
use Alimrun\Ledger\Models\Journal;
use Alimrun\Ledger\Models\Period;
use Alimrun\Ledger\Support\Amount;
use Alimrun\Ledger\Support\Events;
use Alimrun\Ledger\Support\Ref;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Monthly accounting periods (YYYY-MM). A closed period refuses postings.
 */
class PeriodService
{
    public function __construct(
        protected Container $container,
        protected AccountService $accounts,
    ) {}

    public function for(CarbonInterface $at): string
    {
        return $at->format('Y-m');
    }

    public function find(string $period): ?Period
    {
        return Period::query()->where('period', $this->validate($period))->first();
    }

    public function isClosed(string $period): bool
    {
        return (bool) $this->find($period)?->isClosed();
    }

    /**
     * Takes a shared lock on the period row, so a concurrent close waits for
     * this posting to commit and a posting waits for a close in progress.
     */
    public function assertOpen(string $period): void
    {
        $query = Period::query()->where('period', $period);
        $row = (clone $query)->sharedLock()->first();

        if ($row === null) {
            Period::query()->insertOrIgnore([
                'period' => $period,
                'status' => PeriodStatus::Open->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = $query->sharedLock()->first();
        }

        if ($row?->isClosed()) {
            throw new PeriodClosedException("Ledger period [{$period}] is closed.");
        }
    }

    /**
     * Moves the period's revenue and expense balances into retained earnings,
     * then closes it. Returns the closing journal, or null when the period
     * had no income or expense activity.
     */
    public function close(string $period, Model|Ref|array|null $closedBy = null): ?Journal
    {
        $period = $this->validate($period);
        $ledger = $this->container->make(LedgerManager::class);

        return $ledger->transaction(function () use ($period, $closedBy, $ledger): ?Journal {
            $row = $this->lockRow($period);

            if ($row->isClosed()) {
                throw new LedgerException("Ledger period [{$period}] is already closed.");
            }

            $journal = $this->postClosingJournal($ledger, $period, $row->close_count + 1);
            $by = Ref::from($closedBy);

            $row->forceFill([
                'status' => PeriodStatus::Closed,
                'close_count' => $row->close_count + 1,
                'closing_journal_id' => $journal?->id,
                'closed_at' => now(),
                'closed_by_type' => $by?->type,
                'closed_by_id' => $by?->id,
            ])->save();

            Events::dispatch(new PeriodClosed($row, $journal));

            return $journal;
        });
    }

    /**
     * Reopens a closed period and reverses its closing journal.
     */
    public function reopen(string $period, Model|Ref|array|null $reopenedBy = null): Period
    {
        $period = $this->validate($period);
        $ledger = $this->container->make(LedgerManager::class);

        return $ledger->transaction(function () use ($period, $reopenedBy, $ledger): Period {
            $row = $this->lockRow($period);

            if (! $row->isClosed()) {
                throw new LedgerException("Ledger period [{$period}] is not closed.");
            }

            $row->forceFill(['status' => PeriodStatus::Open])->save();

            if ($closing = $row->closingJournal) {
                $ledger->reverse($closing, 'Period reopened', $closing->occurred_at, $reopenedBy);
            }

            $row->forceFill([
                'closing_journal_id' => null,
                'closed_at' => null,
                'closed_by_type' => null,
                'closed_by_id' => null,
            ])->save();

            Events::dispatch(new PeriodReopened($row));

            return $row;
        });
    }

    public function endOf(string $period): Carbon
    {
        return Carbon::parse($this->validate($period).'-01')->endOfMonth()->startOfSecond();
    }

    protected function postClosingJournal(LedgerManager $ledger, string $period, int $closeNumber): ?Journal
    {
        $incomeAccountIds = $this->accounts->all()
            ->filter(fn ($account) => $account->type->isIncomeStatement())
            ->keys()
            ->all();

        if ($incomeAccountIds === []) {
            return null;
        }

        $rows = Entry::query()->toBase()
            ->where('period', $period)
            ->where('origin', '!=', JournalOrigin::Closing->value)
            ->whereIn('account_id', $incomeAccountIds)
            ->selectRaw('account_id, segment, party_type, party_id, SUM(debit) as debit, SUM(credit) as credit')
            ->groupBy('account_id', 'segment', 'party_type', 'party_id')
            ->orderBy('account_id')
            ->get();

        $journal = $ledger->journal('period.closing')
            ->idempotencyKey("period:{$period}:closing:{$closeNumber}")
            ->origin(JournalOrigin::Closing)
            ->occurredAt($this->endOf($period))
            ->description("Close {$period} into retained earnings");

        $retained = $this->accounts->findByRole('retained_earnings');
        $netBySegment = [];

        foreach ($rows as $row) {
            $net = Amount::of($row->debit)->minus(Amount::of($row->credit));

            if ($net->isZero()) {
                continue;
            }

            $party = $row->party_type ? new Ref($row->party_type, $row->party_id) : null;
            $account = $this->accounts->find((int) $row->account_id);

            $net->isPositive()
                ? $journal->credit($account, $net, party: $party, segment: $row->segment, tag: 'closing')
                : $journal->debit($account, $net->negated(), party: $party, segment: $row->segment, tag: 'closing');

            $segmentKey = $row->segment ?? '';
            $netBySegment[$segmentKey] = ($netBySegment[$segmentKey] ?? Amount::zero())->plus($net);
        }

        foreach ($netBySegment as $segment => $net) {
            $segment = $segment === '' ? null : $segment;

            // A debit-heavy net is a loss, which reduces retained earnings.
            $net->isPositive()
                ? $journal->debit($retained, $net, segment: $segment, tag: 'closing')
                : $journal->credit($retained, $net->negated(), segment: $segment, tag: 'closing');
        }

        return $journal->lines() === [] ? null : $journal->post();
    }

    protected function lockRow(string $period): Period
    {
        Period::query()->insertOrIgnore([
            'period' => $period,
            'status' => PeriodStatus::Open->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Period::query()->where('period', $period)->lockForUpdate()->firstOrFail();
    }

    protected function validate(string $period): string
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new LedgerException("Ledger periods are written YYYY-MM; got [{$period}].");
        }

        return $period;
    }
}
