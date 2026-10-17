<?php

namespace Alimrun\Ledger\Exceptions;

class UnbalancedJournalException extends InvalidJournalException
{
    public function __construct(
        public readonly string $totalDebit,
        public readonly string $totalCredit,
        ?string $event = null,
    ) {
        parent::__construct(sprintf(
            'Journal%s does not balance: debits %s, credits %s.',
            $event ? " [{$event}]" : '',
            $totalDebit,
            $totalCredit,
        ));
    }
}
