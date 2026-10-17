<?php

namespace Alimrun\Ledger\Services;

use Alimrun\Ledger\Contracts\Reconciler;
use Alimrun\Ledger\Events\ReconciliationIssueDetected;
use Alimrun\Ledger\LedgerManager;
use Alimrun\Ledger\Models\ReconciliationIssue;
use Alimrun\Ledger\Support\Amount;
use Alimrun\Ledger\Support\Events;
use Alimrun\Ledger\Support\Ref;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;

class ReconciliationService
{
    public function __construct(
        protected Container $container,
        protected Repository $config,
    ) {}

    /**
     * Runs the reconcilers, records each difference as an open issue and
     * resolves issues that have since disappeared.
     *
     * @param  list<class-string<Reconciler>|Reconciler>|null  $reconcilers
     * @return array<string, array{checked: int, differences: int}>
     */
    public function run(?array $reconcilers = null): array
    {
        $ledger = $this->container->make(LedgerManager::class);
        $tolerance = Amount::of($this->config->get('ledger.rounding.tolerance', '0.000010'));
        $summary = [];

        foreach ($reconcilers ?? $this->config->get('ledger.reconcilers', []) as $reconciler) {
            $reconciler = is_string($reconciler) ? $this->container->make($reconciler) : $reconciler;
            $name = $reconciler->name();
            $summary[$name] = ['checked' => 0, 'differences' => 0];

            foreach ($reconciler->expectations() as $expectation) {
                $party = Ref::from($expectation->party);
                $expected = Amount::of($expectation->expected);
                $actual = $ledger->balance($expectation->accounts, $party, side: $expectation->side);
                $difference = $expected->minus($actual);
                $accounts = implode(',', (array) $expectation->accounts);

                $open = ReconciliationIssue::query()->unresolved()
                    ->where('reconciler', $name)
                    ->where('subject', $expectation->subject)
                    ->where('accounts', $accounts)
                    ->where('party_type', $party?->type)
                    ->where('party_id', $party?->id)
                    ->first();

                $summary[$name]['checked']++;

                if ($difference->abs()->isLessThanOrEqualTo($tolerance)) {
                    $open?->resolve('Balances agree again.');

                    continue;
                }

                $summary[$name]['differences']++;

                $issue = $open ?? new ReconciliationIssue([
                    'reconciler' => $name,
                    'subject' => $expectation->subject,
                    'accounts' => $accounts,
                    'party_type' => $party?->type,
                    'party_id' => $party?->id,
                ]);

                $issue->fill([
                    'expected' => (string) $expected,
                    'actual' => (string) $actual,
                    'difference' => (string) $difference,
                    'meta' => $expectation->meta ?: null,
                    'detected_at' => now(),
                ])->save();

                Events::dispatch(new ReconciliationIssueDetected($issue));
            }
        }

        return $summary;
    }
}
