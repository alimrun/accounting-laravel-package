<?php

namespace Alimrun\Ledger\Facades;

use Alimrun\Ledger\LedgerManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Alimrun\Ledger\Posting\JournalBuilder journal(string $event)
 * @method static \Alimrun\Ledger\Models\Journal|null post(\Alimrun\Ledger\Posting\JournalBuilder|\Alimrun\Ledger\Posting\Posting $journal)
 * @method static \Alimrun\Ledger\Models\Journal|null reverse(\Alimrun\Ledger\Models\Journal|int $journal, ?string $reason = null, $occurredAt = null, $reversedBy = null)
 * @method static \Alimrun\Ledger\Models\Journal|null openingBalance($account, $amount, $party = null, $asOf = null, ?string $segment = null)
 * @method static \Alimrun\Ledger\Models\Journal|null find(string $idempotencyKey)
 * @method static \Brick\Math\BigDecimal balance($accounts, $party = null, $asOf = null, ?string $segment = null, $side = null)
 * @method static \Brick\Math\BigDecimal lockBalance($account, $party = null, $side = null)
 * @method static \Alimrun\Ledger\Reports\TrialBalance trialBalance($asOf = null, ?string $segment = null, $from = null, bool $includeZero = false)
 * @method static \Alimrun\Ledger\Reports\IncomeStatement incomeStatement($from = null, $to = null, ?string $segment = null)
 * @method static \Alimrun\Ledger\Reports\BalanceSheet balanceSheet($asOf = null, ?string $segment = null)
 * @method static \Alimrun\Ledger\Reports\AccountStatement statement($accounts, $party = null, $from = null, $to = null, ?string $segment = null, $side = null, ?int $limit = null)
 * @method static \Alimrun\Ledger\Reports\PartyBalances partyBalances($account, $asOf = null, bool $includeZero = false)
 * @method static \Alimrun\Ledger\Models\Journal|null closePeriod(string $period, $closedBy = null)
 * @method static void reopenPeriod(string $period, $reopenedBy = null)
 * @method static list<string> verify()
 * @method static array reconcile(?array $reconcilers = null)
 * @method static int rebuildBalances()
 * @method static \Alimrun\Ledger\Services\AccountService accounts()
 * @method static \Alimrun\Ledger\Models\Account account(string|int|\Alimrun\Ledger\Models\Account $account)
 * @method static \Alimrun\Ledger\Services\PeriodService periods()
 * @method static bool isEnabled()
 * @method static void enable()
 * @method static void disable()
 * @method static mixed transaction(\Closure $callback)
 *
 * @see LedgerManager
 */
class Ledger extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LedgerManager::class;
    }
}
