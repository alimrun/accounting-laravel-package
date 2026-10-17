<?php

namespace Alimrun\Ledger\Reports;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

abstract class Report implements Arrayable, JsonSerializable
{
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
