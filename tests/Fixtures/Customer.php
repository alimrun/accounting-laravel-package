<?php

namespace Alimrun\Ledger\Tests\Fixtures;

use Alimrun\Ledger\Concerns\HasLedger;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasLedger;

    public $timestamps = false;

    protected $guarded = [];
}
