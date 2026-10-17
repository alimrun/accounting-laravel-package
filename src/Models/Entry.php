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
use Illuminate\Support\Carbon;

/**
 * One debit or credit line of a journal.
 *
 * @property int $id
 * @property int $journal_id
 * @property int $line_no
 * @property int $account_id
 * @property string|null $party_type
 * @property string|null $party_id
 * @property string $debit
 * @property string $credit
 * @property string|null $tag
 * @property string|null $segment
 * @property string|null $reference_type
 * @property string|null $reference_id
 * @property string|null $memo
 * @property array|null $dimensions
 * @property Carbon $occurred_at
 * @property string $period
 * @property JournalOrigin $origin
 */
class Entry extends Model
{
    use Immutable;
    use LedgerModel;

    public $timestamps = false;

    protected string $ledgerTable = 'entries';

    protected $guarded = ['id'];

    protected $casts = [
        'debit' => 'decimal:6',
        'credit' => 'decimal:6',
        'dimensions' => 'array',
        'occurred_at' => 'datetime',
        'origin' => JournalOrigin::class,
    ];

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function party(): ?Ref
    {
        return $this->party_type ? new Ref($this->party_type, $this->party_id) : null;
    }

    public function reference(): ?Ref
    {
        return $this->reference_type ? new Ref($this->reference_type, $this->reference_id) : null;
    }

    /**
     * Debit minus credit.
     */
    public function net(): BigDecimal
    {
        return Amount::of($this->debit)->minus(Amount::of($this->credit));
    }

    public function scopeForParty(Builder $query, Model|Ref|array $party): Builder
    {
        $ref = Ref::from($party);

        return $query->where('party_type', $ref->type)->where('party_id', $ref->id);
    }

    public function scopeForReference(Builder $query, Model|Ref|array $reference): Builder
    {
        $ref = Ref::from($reference);

        return $query->where('reference_type', $ref->type)->where('reference_id', $ref->id);
    }
}
