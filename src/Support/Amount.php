<?php

namespace Alimrun\Ledger\Support;

use Alimrun\Ledger\Exceptions\InvalidAmountException;
use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

/**
 * Exact money arithmetic at the ledger's storage scale.
 */
final class Amount
{
    public const SCALE = 12;

    public static function of(BigNumber|int|float|string|null $value): BigDecimal
    {
        if ($value === null || $value === '') {
            return self::zero();
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidAmountException('Amount must be a finite number.');
            }

            // var_export uses the shortest round-trip representation.
            $value = var_export($value, true);
        }

        try {
            return BigDecimal::of($value)->toScale(self::SCALE, RoundingMode::HALF_UP);
        } catch (MathException $e) {
            throw new InvalidAmountException("Invalid amount [{$value}].", previous: $e);
        }
    }

    public static function zero(): BigDecimal
    {
        return BigDecimal::zero()->toScale(self::SCALE);
    }

    public static function format(BigNumber|int|float|string|null $value): string
    {
        return (string) self::of($value);
    }

    /**
     * @param  iterable<BigNumber|int|float|string|null>  $values
     */
    public static function sum(iterable $values): BigDecimal
    {
        $total = self::zero();

        foreach ($values as $value) {
            $total = $total->plus(self::of($value));
        }

        return $total;
    }
}
