<?php

namespace Alimrun\Ledger\Models;

use Alimrun\Ledger\Enums\JournalOrigin;
use Alimrun\Ledger\Models\Concerns\Immutable;
use Alimrun\Ledger\Models\Concerns\LedgerModel;
use Alimrun\Ledger\Support\Amount;
use Alimrun\Ledger\Support\Ref;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One business event, posted as two or more balanced lines.
 *
 * @property int $id
 * @property string $number
 * @property string $event
 * @property string $idempotency_key
 * @property string $payload_hash
 * @property string|null $source_type
 * @property string|null $source_id
 * @property Carbon $occurred_at
 * @property string $period
 * @property string|null $segment
 * @property string $currency
 * @property string $exchange_rate
 * @property JournalOrigin $origin
 * @property string|null $description
 * @property string|null $payment_method
 * @property string|null $transaction_ref
 * @property int|null $reversal_of_id
 * @property string|null $created_by_type
 * @property string|null $created_by_id
 * @property array|null $meta
 * @property Carbon|null $created_at
 */
class Journal extends Model
{
    use Immutable;
    use LedgerModel;

    public const UPDATED_AT = null;

    protected string $ledgerTable = 'journals';

    protected $guarded = ['id'];

    protected $casts = [
        'occurred_at' => 'datetime',
        'origin' => JournalOrigin::class,
        'exchange_rate' => 'decimal:10',
        'meta' => 'array',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class)->orderBy('line_no');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(static::class, 'reversal_of_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(static::class, 'reversal_of_id');
    }

    public function source(): ?Ref
    {
        return $this->source_type ? new Ref($this->source_type, $this->source_id) : null;
    }

    public function createdBy(): ?Ref
    {
        return $this->created_by_type ? new Ref($this->created_by_type, $this->created_by_id) : null;
    }

    public function isReversal(): bool
    {
        return $this->reversal_of_id !== null;
    }

    public function isReversed(): bool
    {
        return $this->reversal()->exists();
    }

    public function totalDebit(): BigDecimal
    {
        return Amount::sum($this->entries->pluck('debit'));
    }

    public function totalCredit(): BigDecimal
    {
        return Amount::sum($this->entries->pluck('credit'));
    }

    public function scopeForSource(Builder $query, Model|Ref|array $source): Builder
    {
        $ref = Ref::from($source);

        return $query->where('source_type', $ref->type)->where('source_id', $ref->id);
    }

    public function scopeForEvent(Builder $query, string $event): Builder
    {
        return $query->where('event', $event);
    }

    public function scopeBetween(Builder $query, $from = null, $to = null): Builder
    {
        return $query
            ->when($from, fn (Builder $q) => $q->where('occurred_at', '>=', Carbon::parse($from)))
            ->when($to, fn (Builder $q) => $q->where('occurred_at', '<=', Carbon::parse($to)));
    }

    public function scopeForParty(Builder $query, Model|Ref|array $party): Builder
    {
        $ref = Ref::from($party);

        return $query->whereIn('id', Entry::query()
            ->select('journal_id')
            ->where('party_type', $ref->type)
            ->where('party_id', $ref->id));
    }
}
