<?php

namespace Alimrun\Ledger\Models;

use Alimrun\Ledger\Enums\NormalSide;
use Alimrun\Ledger\Models\Concerns\LedgerModel;
use Alimrun\Ledger\Support\Amount;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Locked running totals per account and party. Written only by the poster.
 *
 * @property int $id
 * @property int $account_id
 * @property string $party_type
 * @property string $party_id
 * @property string $debit_total
 * @property string $credit_total
 * @property int|null $last_entry_id
 */
class Balance extends Model
{
    use LedgerModel;

    public const CREATED_AT = null;

    protected string $ledgerTable = 'balances';

    protected $guarded = ['id'];

    protected $casts = [
        'debit_total' => 'decimal:12',
        'credit_total' => 'decimal:12',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function balance(NormalSide $side): BigDecimal
    {
        return $side->balance(Amount::of($this->debit_total), Amount::of($this->credit_total));
    }
}
