<?php

namespace Alimrun\Ledger\Posting;

use Alimrun\Ledger\Enums\JournalOrigin;
use Alimrun\Ledger\Exceptions\InvalidAmountException;
use Alimrun\Ledger\LedgerManager;
use Alimrun\Ledger\Models\Account;
use Alimrun\Ledger\Models\Journal;
use Alimrun\Ledger\Support\Amount;
use Alimrun\Ledger\Support\Ref;
use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Traits\Conditionable;

/**
 * Fluent journal builder.
 *
 *     Ledger::journal('order.delivered')
 *         ->idempotencyKey("order:{$order->id}:delivered")
 *         ->source($order)
 *         ->debit('2050', $order->total, party: $customer)
 *         ->credit('2100', $vendorShare, party: $seller, tag: 'goods')
 *         ->credit('4000', $commission, tag: 'commission')
 *         ->post();
 *
 * Zero amounts are skipped, so a rule can add a line for every component
 * without checking each one. Negative amounts are refused.
 */
class JournalBuilder
{
    use Conditionable;

    protected ?string $idempotencyKey = null;

    protected ?CarbonInterface $occurredAt = null;

    protected ?Ref $source = null;

    protected ?string $segment = null;

    protected ?string $currency = null;

    protected string $exchangeRate = '1';

    protected JournalOrigin $origin = JournalOrigin::Live;

    protected ?string $description = null;

    protected ?string $paymentMethod = null;

    protected ?string $transactionRef = null;

    protected ?Ref $createdBy = null;

    protected array $meta = [];

    protected ?int $reversalOfId = null;

    /** @var list<Line> */
    protected array $lines = [];

    public function __construct(
        protected readonly LedgerManager $ledger,
        protected readonly string $event,
    ) {}

    public function idempotencyKey(string $key): static
    {
        $this->idempotencyKey = $key;

        return $this;
    }

    public function key(string $key): static
    {
        return $this->idempotencyKey($key);
    }

    public function occurredAt(CarbonInterface|string|null $at): static
    {
        $this->occurredAt = $at === null ? null : Carbon::parse($at);

        return $this;
    }

    /**
     * The record that caused this journal: an order, a refund, a payout...
     */
    public function source(Model|Ref|array|null $source): static
    {
        $this->source = Ref::from($source);

        return $this;
    }

    /**
     * Business unit, module, branch or channel. Lines inherit it.
     */
    public function segment(?string $segment): static
    {
        $this->segment = $segment;

        return $this;
    }

    /**
     * The currency the event happened in and its rate to the base currency.
     * Line amounts are always in the base currency.
     */
    public function currency(string $currency, BigNumber|int|float|string $exchangeRate = 1): static
    {
        $this->currency = strtoupper($currency);
        $this->exchangeRate = (string) BigDecimal::of(is_float($exchangeRate) ? var_export($exchangeRate, true) : $exchangeRate);

        return $this;
    }

    public function origin(JournalOrigin|string $origin): static
    {
        $this->origin = $origin instanceof JournalOrigin ? $origin : JournalOrigin::from($origin);

        return $this;
    }

    public function description(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function paymentMethod(?string $method): static
    {
        $this->paymentMethod = $method;

        return $this;
    }

    public function transactionRef(?string $reference): static
    {
        $this->transactionRef = $reference;

        return $this;
    }

    public function createdBy(Model|Ref|array|null $actor): static
    {
        $this->createdBy = Ref::from($actor);

        return $this;
    }

    public function meta(array $meta): static
    {
        $this->meta = array_merge($this->meta, $meta);

        return $this;
    }

    /**
     * @internal Used by the ledger when it posts a reversal.
     */
    public function reversalOf(Journal $journal): static
    {
        $this->reversalOfId = $journal->id;

        return $this;
    }

    public function debit(
        string|int|Account $account,
        BigNumber|int|float|string|null $amount,
        Model|Ref|array|null $party = null,
        ?string $tag = null,
        Model|Ref|array|null $reference = null,
        ?string $memo = null,
        ?string $segment = null,
        array $dimensions = [],
    ): static {
        return $this->addLine($account, $amount, true, $party, $tag, $reference, $memo, $segment, $dimensions);
    }

    public function credit(
        string|int|Account $account,
        BigNumber|int|float|string|null $amount,
        Model|Ref|array|null $party = null,
        ?string $tag = null,
        Model|Ref|array|null $reference = null,
        ?string $memo = null,
        ?string $segment = null,
        array $dimensions = [],
    ): static {
        return $this->addLine($account, $amount, false, $party, $tag, $reference, $memo, $segment, $dimensions);
    }

    public function line(Line $line): static
    {
        $this->lines[] = $line;

        return $this;
    }

    /**
     * @return list<Line>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    public function totalDebit(): BigDecimal
    {
        return array_reduce($this->lines, fn (BigDecimal $sum, Line $line) => $sum->plus($line->debit), Amount::zero());
    }

    public function totalCredit(): BigDecimal
    {
        return array_reduce($this->lines, fn (BigDecimal $sum, Line $line) => $sum->plus($line->credit), Amount::zero());
    }

    public function toDraft(): JournalDraft
    {
        return new JournalDraft(
            event: $this->event,
            idempotencyKey: $this->idempotencyKey,
            lines: $this->lines,
            occurredAt: $this->occurredAt,
            source: $this->source,
            segment: $this->segment,
            currency: $this->currency,
            exchangeRate: $this->exchangeRate,
            origin: $this->origin,
            description: $this->description,
            paymentMethod: $this->paymentMethod,
            transactionRef: $this->transactionRef,
            createdBy: $this->createdBy,
            meta: $this->meta,
            reversalOfId: $this->reversalOfId,
        );
    }

    /**
     * Posts the journal. Returns null while the ledger is disabled.
     */
    public function post(): ?Journal
    {
        return $this->ledger->post($this);
    }

    protected function addLine(
        string|int|Account $account,
        BigNumber|int|float|string|null $amount,
        bool $isDebit,
        Model|Ref|array|null $party,
        ?string $tag,
        Model|Ref|array|null $reference,
        ?string $memo,
        ?string $segment,
        array $dimensions,
    ): static {
        $value = Amount::of($amount);

        if ($value->isNegative()) {
            throw new InvalidAmountException(sprintf(
                'Line amounts must not be negative (%s %s on %s); post it on the other side instead.',
                $isDebit ? 'debit' : 'credit',
                $value,
                $account instanceof Account ? $account->code : $account,
            ));
        }

        if ($value->isZero()) {
            return $this;
        }

        $this->lines[] = new Line(
            account: $account,
            debit: $isDebit ? $value : Amount::zero(),
            credit: $isDebit ? Amount::zero() : $value,
            party: Ref::from($party),
            tag: $tag,
            segment: $segment,
            reference: Ref::from($reference),
            memo: $memo,
            dimensions: $dimensions,
        );

        return $this;
    }
}
