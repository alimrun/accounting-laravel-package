<?php

namespace Alimrun\Ledger\Enums;

use Brick\Math\BigDecimal;

enum NormalSide: string
{
    case Debit = 'debit';
    case Credit = 'credit';

    /**
     * Turns debit and credit totals into a balance that is positive on this side.
     */
    public function balance(BigDecimal $debit, BigDecimal $credit): BigDecimal
    {
        return $this === self::Debit ? $debit->minus($credit) : $credit->minus($debit);
    }

    public function opposite(): self
    {
        return $this === self::Debit ? self::Credit : self::Debit;
    }
}
