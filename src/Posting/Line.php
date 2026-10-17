<?php

namespace Alimrun\Ledger\Posting;

use Alimrun\Ledger\Models\Account;
use Alimrun\Ledger\Support\Ref;
use Brick\Math\BigDecimal;

/**
 * One debit or credit line before it is written.
 */
final class Line
{
    public function __construct(
        public readonly string|int|Account $account,
        public readonly BigDecimal $debit,
        public readonly BigDecimal $credit,
        public readonly ?Ref $party = null,
        public readonly ?string $tag = null,
        public readonly ?string $segment = null,
        public readonly ?Ref $reference = null,
        public readonly ?string $memo = null,
        public readonly array $dimensions = [],
    ) {}

    public function withAccount(Account $account): self
    {
        return new self($account, $this->debit, $this->credit, $this->party, $this->tag, $this->segment, $this->reference, $this->memo, $this->dimensions);
    }

    public function withSegment(?string $segment): self
    {
        return new self($this->account, $this->debit, $this->credit, $this->party, $this->tag, $segment, $this->reference, $this->memo, $this->dimensions);
    }

    /**
     * The same line on the other side, used by reversals.
     */
    public function flipped(): self
    {
        return new self($this->account, $this->credit, $this->debit, $this->party, $this->tag, $this->segment, $this->reference, $this->memo, $this->dimensions);
    }

    public function isDebit(): bool
    {
        return $this->debit->isPositive();
    }

    public function amount(): BigDecimal
    {
        return $this->isDebit() ? $this->debit : $this->credit;
    }

    public function accountCode(): string
    {
        return $this->account instanceof Account ? $this->account->code : (string) $this->account;
    }
}
