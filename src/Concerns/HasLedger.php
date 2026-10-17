<?php

namespace Alimrun\Ledger\Concerns;

use Alimrun\Ledger\Enums\NormalSide;
use Alimrun\Ledger\LedgerManager;
use Alimrun\Ledger\Models\Account;
use Alimrun\Ledger\Models\Entry;
use Alimrun\Ledger\Models\Journal;
use Alimrun\Ledger\Reports\AccountStatement;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Add to any model that appears as a party (customer, vendor, employee) or
 * as a journal source (order, invoice, payout).
 *
 *     $customer->ledgerBalance('2000');
 *     $seller->ledgerBalance(['2100', '1100'], NormalSide::Credit);
 *     $order->ledgerJournals()->get();
 */
trait HasLedger
{
    /**
     * Lines where this model is the party.
     */
    public function ledgerEntries(): Builder
    {
        return Entry::query()
            ->where('party_type', $this->getMorphClass())
            ->where('party_id', (string) $this->getKey());
    }

    /**
     * Journals this model caused.
     */
    public function ledgerJournals(): Builder
    {
        return Journal::query()
            ->where('source_type', $this->getMorphClass())
            ->where('source_id', (string) $this->getKey())
            ->orderBy('id');
    }

    public function ledgerBalance(string|int|Account|array $accounts, NormalSide|string|null $side = null, CarbonInterface|string|null $asOf = null): BigDecimal
    {
        return app(LedgerManager::class)->balance($accounts, $this, $asOf, side: $side);
    }

    public function ledgerStatement(string|int|Account|array $accounts, CarbonInterface|string|null $from = null, CarbonInterface|string|null $to = null, NormalSide|string|null $side = null): AccountStatement
    {
        return app(LedgerManager::class)->statement($accounts, $this, $from, $to, side: $side);
    }
}
