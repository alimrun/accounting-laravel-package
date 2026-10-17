<?php

namespace Alimrun\Ledger\Http\Controllers;

use Alimrun\Ledger\Support\Ref;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

abstract class Controller
{
    protected function authorizeView(): void
    {
        Gate::authorize(config('ledger.routes.gates.view', 'viewLedger'));
    }

    protected function authorizeManage(): void
    {
        Gate::authorize(config('ledger.routes.gates.manage', 'manageLedger'));
    }

    protected function partyFrom(Request $request, string $prefix = 'party'): ?Ref
    {
        $type = $request->input("{$prefix}_type");
        $id = $request->input("{$prefix}_id");

        return $type && $id !== null && $id !== '' ? new Ref((string) $type, (string) $id) : null;
    }
}
