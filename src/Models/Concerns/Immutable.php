<?php

namespace Alimrun\Ledger\Models\Concerns;

use Alimrun\Ledger\Exceptions\ImmutableRecordException;

/**
 * Journals and entries are append-only: a correction is a reversing
 * journal plus a new one.
 */
trait Immutable
{
    public static function bootImmutable(): void
    {
        static::updating(function ($model): void {
            throw new ImmutableRecordException(class_basename($model).' records are append-only; post a reversal instead.');
        });

        static::deleting(function ($model): void {
            throw new ImmutableRecordException(class_basename($model).' records are append-only; post a reversal instead.');
        });
    }
}
