<?php

namespace Alimrun\Ledger\Models;

use Alimrun\Ledger\Enums\AccountType;
use Alimrun\Ledger\Enums\NormalSide;
use Alimrun\Ledger\Models\Concerns\LedgerModel;
use Alimrun\Ledger\Support\Ref;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property AccountType $type
 * @property NormalSide $normal_side
 * @property int|null $parent_id
 * @property string|null $role
 * @property string|null $party_types
 * @property bool $is_group
 * @property bool $is_system
 * @property bool $is_active
 * @property string|null $currency
 * @property string|null $description
 * @property array|null $meta
 */
class Account extends Model
{
    use LedgerModel;

    protected string $ledgerTable = 'accounts';

    protected $guarded = ['id'];

    protected $casts = [
        'type' => AccountType::class,
        'normal_side' => NormalSide::class,
        'is_group' => 'boolean',
        'is_system' => 'boolean',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(static::class, 'parent_id')->orderBy('code');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(Balance::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType(Builder $query, AccountType|string $type): Builder
    {
        return $query->where('type', $type instanceof AccountType ? $type->value : $type);
    }

    /**
     * Null means the account never takes a party; ['*'] means any party.
     *
     * @return list<string>|null
     */
    public function partyTypes(): ?array
    {
        if ($this->party_types === null || $this->party_types === '') {
            return null;
        }

        return array_values(array_filter(array_map('trim', explode(',', $this->party_types))));
    }

    public function requiresParty(): bool
    {
        return $this->partyTypes() !== null;
    }

    public function acceptsParty(?Ref $party): bool
    {
        $types = $this->partyTypes();

        if ($types === null) {
            return $party === null;
        }

        if ($party === null) {
            return false;
        }

        return in_array('*', $types, true) || in_array($party->type, $types, true);
    }

    public function label(): string
    {
        return $this->code.' '.$this->name;
    }
}
