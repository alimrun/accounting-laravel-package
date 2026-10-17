<?php

namespace Alimrun\Ledger\Http\Controllers;

use Alimrun\Ledger\Enums\AccountType;
use Alimrun\Ledger\LedgerManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    public function __construct(protected LedgerManager $ledger) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeView();

        $accounts = $request->boolean('tree')
            ? $this->ledger->accounts()->tree()
            : $this->ledger->accounts()->all()->values();

        return response()->json(['data' => $accounts]);
    }

    public function show(string $code): JsonResponse
    {
        $this->authorizeView();

        $account = $this->ledger->account($code);

        return response()->json(['data' => $account->toArray() + [
            'balance' => (string) $this->ledger->balance($account),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage();

        $account = $this->ledger->accounts()->create($this->validated($request));

        return response()->json(['data' => $account], 201);
    }

    public function update(Request $request, string $code): JsonResponse
    {
        $this->authorizeManage();

        $account = $this->ledger->accounts()->update($this->ledger->account($code), $this->validated($request, partial: true));

        return response()->json(['data' => $account]);
    }

    public function deactivate(string $code): JsonResponse
    {
        $this->authorizeManage();

        return response()->json(['data' => $this->ledger->accounts()->deactivate($this->ledger->account($code))]);
    }

    public function activate(string $code): JsonResponse
    {
        $this->authorizeManage();

        return response()->json(['data' => $this->ledger->accounts()->activate($this->ledger->account($code))]);
    }

    protected function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'code' => [$required, 'string', 'max:32'],
            'name' => [$required, 'string', 'max:255'],
            'type' => [$required, Rule::enum(AccountType::class)],
            'normal_side' => ['nullable', Rule::in(['debit', 'credit'])],
            'parent' => ['nullable', 'string'],
            'party' => ['nullable'],
            'group' => ['sometimes', 'boolean'],
            'currency' => ['nullable', 'string', 'size:3'],
            'description' => ['nullable', 'string'],
        ]);
    }
}
