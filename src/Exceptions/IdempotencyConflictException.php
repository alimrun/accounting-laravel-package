<?php

namespace Alimrun\Ledger\Exceptions;

class IdempotencyConflictException extends LedgerException
{
    public function __construct(public readonly string $idempotencyKey)
    {
        parent::__construct("Idempotency key [{$idempotencyKey}] was already posted with different lines.");
    }
}
