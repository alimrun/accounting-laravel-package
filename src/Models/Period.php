<?php

namespace Alimrun\Ledger\Models;

use Alimrun\Ledger\Enums\PeriodStatus;
use Alimrun\Ledger\Models\Concerns\LedgerModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $period
 * @property PeriodStatus $status
 * @property int $close_count
 * @property int|null $closing_journal_id
 */
class Period extends Model
{
    use LedgerModel;

    protected string $ledgerTable = 'periods';

    protected $guarded = ['id'];

    protected $casts = [
        'status' => PeriodStatus::class,
        'closed_at' => 'datetime',
    ];

    public function closingJournal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'closing_journal_id');
    }

    public function isClosed(): bool
    {
        return $this->status === PeriodStatus::Closed;
    }
}
