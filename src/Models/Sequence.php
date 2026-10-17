<?php

namespace Alimrun\Ledger\Models;

use Alimrun\Ledger\Models\Concerns\LedgerModel;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property int $value
 */
class Sequence extends Model
{
    use LedgerModel;

    public const CREATED_AT = null;

    protected string $ledgerTable = 'sequences';

    protected $guarded = ['id'];

    protected $casts = ['value' => 'integer'];
}
