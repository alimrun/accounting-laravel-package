<?php

namespace Alimrun\Ledger\Posting;

use Alimrun\Ledger\Enums\JournalOrigin;
use Alimrun\Ledger\Support\Ref;
use Carbon\CarbonInterface;

/**
 * Everything a journal needs, collected by the builder.
 */
final class JournalDraft
{
    /**
     * @param  list<Line>  $lines
     */
    public function __construct(
        public readonly string $event,
        public readonly ?string $idempotencyKey,
        public readonly array $lines,
        public readonly ?CarbonInterface $occurredAt = null,
        public readonly ?Ref $source = null,
        public readonly ?string $segment = null,
        public readonly ?string $currency = null,
        public readonly string $exchangeRate = '1',
        public readonly JournalOrigin $origin = JournalOrigin::Live,
        public readonly ?string $description = null,
        public readonly ?string $paymentMethod = null,
        public readonly ?string $transactionRef = null,
        public readonly ?Ref $createdBy = null,
        public readonly array $meta = [],
        public readonly ?int $reversalOfId = null,
    ) {}
}
