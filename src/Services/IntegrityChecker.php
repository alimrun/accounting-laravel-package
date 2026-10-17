<?php

namespace Alimrun\Ledger\Services;

use Alimrun\Ledger\Models\Balance;
use Alimrun\Ledger\Models\Entry;
use Alimrun\Ledger\Models\Journal;
use Alimrun\Ledger\Support\Amount;

/**
 * Proves the books are internally consistent. Run it nightly and in tests.
 */
class IntegrityChecker
{
    public function __construct(protected BalanceService $balances) {}

    /**
     * @return list<string> problems found; empty when the books are sound
     */
    public function check(): array
    {
        $problems = [];
        $entries = Entry::query()->toBase();
        $journals = (new Journal)->getTable();
        $entriesTable = (new Entry)->getTable();

        foreach ((clone $entries)
            ->selectRaw('journal_id, SUM(debit) as debit, SUM(credit) as credit')
            ->groupBy('journal_id')
            ->havingRaw('ABS(SUM(debit) - SUM(credit)) > 0.0000005')
            ->limit(100)
            ->get() as $row) {
            $problems[] = sprintf('Journal #%d does not balance: debits %s, credits %s.', $row->journal_id, Amount::of($row->debit), Amount::of($row->credit));
        }

        foreach (Journal::query()->toBase()
            ->select("{$journals}.id")
            ->whereRaw("(SELECT COUNT(*) FROM {$entriesTable} WHERE {$entriesTable}.journal_id = {$journals}.id) < 2")
            ->limit(100)
            ->pluck('id') as $id) {
            $problems[] = "Journal #{$id} has fewer than two lines.";
        }

        foreach ((clone $entries)
            ->whereRaw('NOT ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0))')
            ->limit(100)
            ->pluck('id') as $id) {
            $problems[] = "Entry #{$id} must have exactly one positive side.";
        }

        if ($count = Journal::query()->whereNull('number')->count()) {
            $problems[] = "{$count} journal(s) have no number.";
        }

        $totals = (clone $entries)->selectRaw('SUM(debit) as debit, SUM(credit) as credit')->first();

        if (! Amount::of($totals->debit ?? 0)->isEqualTo(Amount::of($totals->credit ?? 0))) {
            $problems[] = sprintf('The trial balance does not net to zero: debits %s, credits %s.', Amount::of($totals->debit), Amount::of($totals->credit));
        }

        return array_merge($problems, $this->checkBalances());
    }

    /**
     * @return list<string>
     */
    protected function checkBalances(): array
    {
        $problems = [];
        $expected = [];

        Entry::query()->toBase()
            ->when(! $this->balances->tracksAll(), fn ($q) => $q->whereNotNull('party_type'))
            ->selectRaw('account_id, party_type, party_id, SUM(debit) as debit, SUM(credit) as credit')
            ->groupBy('account_id', 'party_type', 'party_id')
            ->get()
            ->each(function ($row) use (&$expected): void {
                $expected[$row->account_id.'|'.($row->party_type ?? '').'|'.($row->party_id ?? '')] = [Amount::of($row->debit), Amount::of($row->credit)];
            });

        foreach (Balance::query()->toBase()->cursor() as $row) {
            $key = $row->account_id.'|'.$row->party_type.'|'.$row->party_id;
            [$debit, $credit] = $expected[$key] ?? [Amount::zero(), Amount::zero()];
            unset($expected[$key]);

            if (! $debit->isEqualTo(Amount::of($row->debit_total)) || ! $credit->isEqualTo(Amount::of($row->credit_total))) {
                $problems[] = sprintf(
                    'Balance row #%d (%s) holds %s / %s but its entries sum to %s / %s.',
                    $row->id, $key, Amount::of($row->debit_total), Amount::of($row->credit_total), $debit, $credit,
                );
            }
        }

        foreach (array_keys($expected) as $key) {
            $problems[] = "Entries for {$key} have no balance row.";
        }

        return $problems;
    }
}
