<?php

namespace Alimrun\Ledger\Support;

use Alimrun\Ledger\Exceptions\LedgerException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * A polymorphic pointer (type + id) to a party, a source or a reference.
 *
 * The type is the model's morph class, so register a morph map to keep it
 * short and stable ("customer" rather than "App\Models\User").
 */
final class Ref
{
    public function __construct(
        public readonly string $type,
        public readonly string $id,
    ) {
        if ($type === '' || $id === '') {
            throw new LedgerException('A ledger reference needs a type and an id.');
        }
    }

    /**
     * Accepts a model, a Ref, a [type, id] pair or null.
     */
    public static function from(mixed $value): ?self
    {
        return match (true) {
            $value === null => null,
            $value instanceof self => $value,
            $value instanceof Model => new self($value->getMorphClass(), (string) $value->getKey()),
            is_array($value) && count($value) === 2 => new self((string) array_values($value)[0], (string) array_values($value)[1]),
            default => throw new LedgerException('Pass a model, a Ref or a [type, id] pair.'),
        };
    }

    public static function make(string $type, int|string $id): self
    {
        return new self($type, (string) $id);
    }

    public function key(): string
    {
        return $this->type.':'.$this->id;
    }

    public function is(?self $other): bool
    {
        return $other !== null && $other->type === $this->type && $other->id === $this->id;
    }

    /**
     * Loads the model behind this reference, if its type maps to one.
     */
    public function model(): ?Model
    {
        $class = Relation::getMorphedModel($this->type) ?? $this->type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        return $class::query()->find($this->id);
    }

    /**
     * @return array{type: string, id: string}
     */
    public function toArray(): array
    {
        return ['type' => $this->type, 'id' => $this->id];
    }
}
