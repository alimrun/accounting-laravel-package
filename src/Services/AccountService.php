<?php

namespace Alimrun\Ledger\Services;

use Alimrun\Ledger\Enums\AccountType;
use Alimrun\Ledger\Enums\NormalSide;
use Alimrun\Ledger\Exceptions\AccountNotFoundException;
use Alimrun\Ledger\Exceptions\LedgerException;
use Alimrun\Ledger\Models\Account;
use Alimrun\Ledger\Models\Entry;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Collection;

/**
 * The chart of accounts. Accounts are cached for the request, since a
 * chart is small and every posting line resolves one.
 */
class AccountService
{
    /** @var Collection<int, Account>|null keyed by id */
    protected ?Collection $byId = null;

    /** @var array<string, Account> */
    protected array $byCode = [];

    public const ROLES = ['rounding', 'opening_balance_equity', 'retained_earnings'];

    public function __construct(protected Repository $config) {}

    /**
     * @return Collection<int, Account> keyed by id, ordered by code
     */
    public function all(): Collection
    {
        if ($this->byId === null) {
            $this->byId = Account::query()->orderBy('code')->get()->keyBy('id');
            $this->byCode = $this->byId->keyBy('code')->all();
        }

        return $this->byId;
    }

    public function find(string|int|Account $account): Account
    {
        if ($account instanceof Account) {
            return $account;
        }

        $found = $this->lookup($account);

        if ($found === null) {
            // Another process may have added it since the cache was built.
            $this->flush();
            $found = $this->lookup($account);
        }

        return $found ?? throw new AccountNotFoundException("Ledger account [{$account}] does not exist.");
    }

    public function exists(string $code): bool
    {
        $this->all();

        return isset($this->byCode[$code]);
    }

    public function findByRole(string $role): Account
    {
        if ($code = $this->config->get("ledger.accounts.{$role}")) {
            return $this->find((string) $code);
        }

        return $this->all()->firstWhere('role', $role)
            ?? throw new AccountNotFoundException("No ledger account has the [{$role}] role. Seed a chart or set ledger.accounts.{$role}.");
    }

    public function findRoleOrNull(string $role): ?Account
    {
        try {
            return $this->findByRole($role);
        } catch (AccountNotFoundException) {
            return null;
        }
    }

    /**
     * The account and every account below it.
     *
     * @return list<int>
     */
    public function descendantIds(Account $account): array
    {
        $ids = [$account->id];
        $children = $this->all()->groupBy('parent_id');
        $queue = [$account->id];

        while ($queue) {
            $parentId = array_shift($queue);

            foreach ($children->get($parentId, []) as $child) {
                $ids[] = $child->id;
                $queue[] = $child->id;
            }
        }

        return $ids;
    }

    /**
     * Root accounts with their children loaded recursively.
     *
     * @return Collection<int, Account>
     */
    public function tree(): Collection
    {
        $accounts = $this->all();
        $children = $accounts->groupBy('parent_id');

        $attach = function (Account $account) use (&$attach, $children): Account {
            $account->setRelation('children', $children->get($account->id, collect())->map($attach)->values());

            return $account;
        };

        return $accounts->whereNull('parent_id')->map($attach)->values();
    }

    /**
     * @param  array{code: string, name: string, type: string|AccountType, parent?: string|null, party?: string|array|null, group?: bool, role?: string|null, normal_side?: string|NormalSide|null, is_system?: bool, currency?: string|null, description?: string|null, meta?: array|null}  $attributes
     */
    public function create(array $attributes): Account
    {
        $data = $this->normalize($attributes);

        if (Account::query()->where('code', $data['code'])->exists()) {
            throw new LedgerException("Ledger account [{$data['code']}] already exists.");
        }

        $account = Account::query()->create($data);
        $this->flush();

        return $account;
    }

    public function update(Account $account, array $attributes): Account
    {
        $data = $this->normalize(array_merge($this->denormalize($account), $attributes), $account);

        if ($this->hasEntries($account)) {
            foreach (['type', 'normal_side'] as $field) {
                $current = $account->{$field}?->value;

                if ($data[$field] !== $current) {
                    throw new LedgerException("Account [{$account->code}] has entries; its {$field} cannot change.");
                }
            }

            if ($data['is_group'] && ! $account->is_group) {
                throw new LedgerException("Account [{$account->code}] has entries and cannot become a group account.");
            }
        }

        $account->fill($data)->save();
        $this->flush();

        return $account;
    }

    public function deactivate(Account $account): Account
    {
        if ($account->role !== null) {
            throw new LedgerException("Account [{$account->code}] holds the [{$account->role}] role and cannot be deactivated.");
        }

        $account->forceFill(['is_active' => false])->save();
        $this->flush();

        return $account;
    }

    public function activate(Account $account): Account
    {
        $account->forceFill(['is_active' => true])->save();
        $this->flush();

        return $account;
    }

    public function delete(Account $account): void
    {
        if ($account->is_system || $account->role !== null) {
            throw new LedgerException("Account [{$account->code}] is a system account and cannot be deleted.");
        }

        if ($this->hasEntries($account)) {
            throw new LedgerException("Account [{$account->code}] has entries; deactivate it instead.");
        }

        if (Account::query()->where('parent_id', $account->id)->exists()) {
            throw new LedgerException("Account [{$account->code}] has child accounts.");
        }

        $account->balances()->delete();
        $account->delete();
        $this->flush();
    }

    /**
     * Creates missing accounts from a preset name, a PHP file or an array.
     * Existing codes are left untouched.
     *
     * @return int accounts created
     */
    public function seed(string|array|null $chart = null): int
    {
        $accounts = $this->loadChart($chart ?? $this->config->get('ledger.chart', 'standard'));

        try {
            return Account::query()->getConnection()->transaction(function () use ($accounts): int {
                $created = 0;

                foreach ($accounts as $attributes) {
                    if ($this->exists((string) $attributes['code'])) {
                        continue;
                    }

                    $this->create($attributes);
                    $created++;
                }

                return $created;
            });
        } finally {
            $this->flush();
        }
    }

    /**
     * @return list<array>
     */
    public function loadChart(string|array $chart): array
    {
        if (is_array($chart)) {
            return $chart;
        }

        $path = is_file($chart) ? $chart : __DIR__.'/../../resources/charts/'.$chart.'.php';

        if (! is_file($path)) {
            throw new LedgerException("Chart of accounts [{$chart}] was not found.");
        }

        return require $path;
    }

    public function hasEntries(Account $account): bool
    {
        return Entry::query()->where('account_id', $account->id)->exists();
    }

    public function flush(): void
    {
        $this->byId = null;
        $this->byCode = [];
    }

    protected function lookup(string|int $account): ?Account
    {
        $this->all();

        return is_int($account) ? $this->byId->get($account) : ($this->byCode[$account] ?? null);
    }

    protected function normalize(array $attributes, ?Account $existing = null): array
    {
        foreach (['code', 'name', 'type'] as $required) {
            if (! isset($attributes[$required]) || $attributes[$required] === '') {
                throw new LedgerException("Ledger accounts need a {$required}.");
            }
        }

        $type = $attributes['type'] instanceof AccountType ? $attributes['type'] : AccountType::from($attributes['type']);
        $side = $attributes['normal_side'] ?? null;
        $side = $side instanceof NormalSide ? $side : ($side ? NormalSide::from($side) : $type->normalSide());

        $parentId = null;

        if (! empty($attributes['parent'])) {
            $parent = $this->find(is_int($attributes['parent']) ? $attributes['parent'] : (string) $attributes['parent']);

            if (! $parent->is_group) {
                throw new LedgerException("Parent account [{$parent->code}] must be a group account.");
            }

            if ($parent->type !== $type) {
                throw new LedgerException("Account [{$attributes['code']}] must have the same type as its parent [{$parent->code}].");
            }

            if ($existing && in_array($parent->id, $this->descendantIds($existing), true)) {
                throw new LedgerException("Account [{$existing->code}] cannot sit below itself.");
            }

            $parentId = $parent->id;
        }

        $role = $attributes['role'] ?? null;

        if ($role !== null && ! in_array($role, self::ROLES, true)) {
            throw new LedgerException("Unknown account role [{$role}].");
        }

        $party = $attributes['party'] ?? $attributes['party_types'] ?? null;

        return [
            'code' => (string) $attributes['code'],
            'name' => $attributes['name'],
            'type' => $type->value,
            'normal_side' => $side->value,
            'parent_id' => $parentId,
            'role' => $role,
            'party_types' => match (true) {
                $party === null, $party === '', $party === [] => null,
                is_array($party) => implode(',', $party),
                default => (string) $party,
            },
            'is_group' => (bool) ($attributes['group'] ?? $attributes['is_group'] ?? false),
            'is_system' => (bool) ($attributes['is_system'] ?? $role !== null),
            'is_active' => (bool) ($attributes['is_active'] ?? true),
            'currency' => $attributes['currency'] ?? null,
            'description' => $attributes['description'] ?? null,
            'meta' => $attributes['meta'] ?? null,
        ];
    }

    protected function denormalize(Account $account): array
    {
        return [
            'code' => $account->code,
            'name' => $account->name,
            'type' => $account->type,
            'normal_side' => $account->normal_side,
            'parent' => $account->parent_id,
            'role' => $account->role,
            'party' => $account->party_types,
            'group' => $account->is_group,
            'is_system' => $account->is_system,
            'is_active' => $account->is_active,
            'currency' => $account->currency,
            'description' => $account->description,
            'meta' => $account->meta,
        ];
    }
}
