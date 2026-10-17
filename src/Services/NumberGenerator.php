<?php

namespace Alimrun\Ledger\Services;

use Alimrun\Ledger\Models\Journal;
use Alimrun\Ledger\Models\Sequence;
use Illuminate\Contracts\Config\Repository;

/**
 * Assigns journal numbers such as JV-2026-00000001.
 *
 * The poster calls this last, after the balance rows are locked, so the
 * sequence row is held for as short a time as possible.
 */
class NumberGenerator
{
    public function __construct(protected Repository $config) {}

    public function assign(Journal $journal): string
    {
        $number = $this->format($journal, $this->config->get('ledger.numbering.strategy', 'gapless') === 'fast'
            ? $journal->id
            : $this->next($this->sequenceName($journal)));

        // The journal row is otherwise immutable; the triggers allow this one
        // write while the number is still null.
        $journal->newQuery()->toBase()
            ->where('id', $journal->id)
            ->whereNull('number')
            ->update(['number' => $number]);

        $journal->setAttribute('number', $number);
        $journal->syncOriginalAttribute('number');

        return $number;
    }

    public function next(string $name): int
    {
        $query = Sequence::query()->where('name', $name);
        $row = (clone $query)->lockForUpdate()->first();

        if ($row === null) {
            Sequence::query()->insertOrIgnore(['name' => $name, 'value' => 0, 'updated_at' => now()]);
            $row = $query->lockForUpdate()->firstOrFail();
        }

        $next = $row->value + 1;

        Sequence::query()->toBase()->where('id', $row->id)->update(['value' => $next, 'updated_at' => now()]);

        return $next;
    }

    protected function sequenceName(Journal $journal): string
    {
        return match ($this->config->get('ledger.numbering.reset', 'yearly')) {
            'monthly' => 'journal:'.$journal->occurred_at->format('Y-m'),
            'never' => 'journal',
            default => 'journal:'.$journal->occurred_at->format('Y'),
        };
    }

    protected function format(Journal $journal, int $value): string
    {
        $at = $journal->occurred_at;

        return strtr($this->config->get('ledger.numbering.format', 'JV-{Y}-{number}'), [
            '{Y}' => $at->format('Y'),
            '{y}' => $at->format('y'),
            '{m}' => $at->format('m'),
            '{number}' => str_pad((string) $value, (int) $this->config->get('ledger.numbering.pad', 8), '0', STR_PAD_LEFT),
        ]);
    }
}
