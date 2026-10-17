<?php

namespace Alimrun\Ledger\Http\Controllers;

use Alimrun\Ledger\Enums\JournalOrigin;
use Alimrun\Ledger\LedgerManager;
use Alimrun\Ledger\Models\Entry;
use Alimrun\Ledger\Models\Journal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JournalController extends Controller
{
    public function __construct(protected LedgerManager $ledger) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeView();

        $party = $this->partyFrom($request);
        $source = $this->partyFrom($request, 'source');

        $journals = Journal::query()
            ->withSum('entries as total', 'debit')
            ->when($request->query('event'), fn ($q, $event) => $q->where('event', $event))
            ->when($request->query('segment'), fn ($q, $segment) => $q->where('segment', $segment))
            ->when($request->query('origin'), fn ($q, $origin) => $q->where('origin', $origin))
            ->when($request->query('number'), fn ($q, $number) => $q->where('number', $number))
            ->when($request->query('transaction_ref'), fn ($q, $ref) => $q->where('transaction_ref', $ref))
            ->when($request->query('account'), fn ($q, $code) => $q->whereIn('id', Entry::query()->select('journal_id')->where('account_id', $this->ledger->account($code)->id)))
            ->when($party, fn ($q) => $q->forParty($party))
            ->when($source, fn ($q) => $q->forSource($source))
            ->between($request->query('from'), $request->query('to'))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 25), 200));

        return response()->json($journals);
    }

    public function show(int $journal): JsonResponse
    {
        $this->authorizeView();

        $journal = Journal::query()->with(['entries.account:id,code,name', 'reversal:id,number,reversal_of_id', 'reversalOf:id,number'])->findOrFail($journal);

        return response()->json(['data' => $journal]);
    }

    /**
     * Manual journal, for an accountant's adjustment or an expense entry.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage();

        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:191'],
            'event' => ['sometimes', 'string', 'max:100'],
            'occurred_at' => ['sometimes', 'date'],
            'description' => ['nullable', 'string', 'max:500'],
            'segment' => ['nullable', 'string', 'max:64'],
            'transaction_ref' => ['nullable', 'string', 'max:191'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account' => ['required', 'string'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.party_type' => ['nullable', 'string'],
            'lines.*.party_id' => ['nullable'],
            'lines.*.tag' => ['nullable', 'string', 'max:64'],
            'lines.*.memo' => ['nullable', 'string', 'max:500'],
        ]);

        $journal = $this->ledger->journal($data['event'] ?? 'manual')
            ->idempotencyKey('manual:'.$data['idempotency_key'])
            ->origin(JournalOrigin::Manual)
            ->occurredAt($data['occurred_at'] ?? null)
            ->description($data['description'] ?? null)
            ->segment($data['segment'] ?? null)
            ->transactionRef($data['transaction_ref'] ?? null)
            ->createdBy($request->user());

        foreach ($data['lines'] as $line) {
            $party = ! empty($line['party_type']) ? [$line['party_type'], (string) $line['party_id']] : null;

            $journal
                ->debit($line['account'], $line['debit'] ?? null, party: $party, tag: $line['tag'] ?? null, memo: $line['memo'] ?? null)
                ->credit($line['account'], $line['credit'] ?? null, party: $party, tag: $line['tag'] ?? null, memo: $line['memo'] ?? null);
        }

        return response()->json(['data' => $journal->post()?->load('entries')], 201);
    }

    public function reverse(Request $request, int $journal): JsonResponse
    {
        $this->authorizeManage();

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
            'occurred_at' => ['sometimes', 'date'],
        ]);

        $reversal = $this->ledger->reverse($journal, $data['reason'] ?? null, $data['occurred_at'] ?? null, $request->user());

        return response()->json(['data' => $reversal?->load('entries')], 201);
    }
}
