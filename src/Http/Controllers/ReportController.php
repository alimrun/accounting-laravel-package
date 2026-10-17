<?php

namespace Alimrun\Ledger\Http\Controllers;

use Alimrun\Ledger\LedgerManager;
use Alimrun\Ledger\Models\Period;
use Alimrun\Ledger\Models\ReconciliationIssue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(protected LedgerManager $ledger) {}

    public function trialBalance(Request $request): JsonResponse
    {
        $this->authorizeView();

        return response()->json($this->ledger->trialBalance($request->query('as_of'), $request->query('segment'), $request->query('from'), $request->boolean('include_zero')));
    }

    public function incomeStatement(Request $request): JsonResponse
    {
        $this->authorizeView();

        return response()->json($this->ledger->incomeStatement($request->query('from'), $request->query('to'), $request->query('segment')));
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        $this->authorizeView();

        return response()->json($this->ledger->balanceSheet($request->query('as_of'), $request->query('segment')));
    }

    public function statement(Request $request): JsonResponse
    {
        $this->authorizeView();

        $request->validate([
            'accounts' => ['required'],
            'side' => ['nullable', 'in:debit,credit'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);

        return response()->json($this->ledger->statement(
            (array) $request->query('accounts'),
            $this->partyFrom($request),
            $request->query('from'),
            $request->query('to'),
            $request->query('segment'),
            $request->query('side'),
            $request->integer('limit', 5000),
        ));
    }

    public function partyBalances(Request $request, string $code): JsonResponse
    {
        $this->authorizeView();

        return response()->json($this->ledger->partyBalances($code, $request->query('as_of'), $request->boolean('include_zero')));
    }

    public function periods(): JsonResponse
    {
        $this->authorizeView();

        return response()->json(['data' => Period::query()->orderByDesc('period')->get()]);
    }

    public function closePeriod(Request $request, string $period): JsonResponse
    {
        $this->authorizeManage();

        return response()->json(['data' => $this->ledger->closePeriod($period, $request->user())]);
    }

    public function reopenPeriod(Request $request, string $period): JsonResponse
    {
        $this->authorizeManage();

        $this->ledger->reopenPeriod($period, $request->user());

        return response()->json(['data' => Period::query()->where('period', $period)->first()]);
    }

    public function reconciliationIssues(Request $request): JsonResponse
    {
        $this->authorizeView();

        return response()->json(ReconciliationIssue::query()
            ->when(! $request->boolean('include_resolved'), fn ($q) => $q->unresolved())
            ->orderByDesc('detected_at')
            ->paginate(50));
    }
}
