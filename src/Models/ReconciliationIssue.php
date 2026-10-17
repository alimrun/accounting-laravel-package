<?php

namespace Alimrun\Ledger\Models;

use Alimrun\Ledger\Models\Concerns\LedgerModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A difference between an external balance and the ledger.
 *
 * @property int $id
 * @property string $reconciler
 * @property string|null $subject
 * @property string|null $accounts
 * @property string|null $party_type
 * @property string|null $party_id
 * @property string $expected
 * @property string $actual
 * @property string $difference
 */
class ReconciliationIssue extends Model
{
    use LedgerModel;

    protected string $ledgerTable = 'reconciliation_issues';

    protected $guarded = ['id'];

    protected $casts = [
        'expected' => 'decimal:12',
        'actual' => 'decimal:12',
        'difference' => 'decimal:12',
        'meta' => 'array',
        'detected_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function resolve(?string $note = null): void
    {
        $this->forceFill(['resolved_at' => now(), 'resolution_note' => $note])->save();
    }
}
