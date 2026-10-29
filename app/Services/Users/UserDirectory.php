<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Category;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;

final class UserDirectory
{
    private const LIST_COLUMNS = [
        'users.id', 'users.name', 'users.email', 'users.is_available', 'users.last_assigned_at', 'users.created_at',
    ];

    private const RELATIONS = ['roles:id,name', 'categories:id,name'];

    /**
     * @param  array{q: string|null, role: string|null, per_page: int}  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $prefix = $filters['q'] === null ? null : addcslashes($filters['q'], '%_\\').'%';
        $role = $filters['role'];

        return User::query()
            ->select(self::LIST_COLUMNS)
            ->with(self::RELATIONS)
            ->when($prefix !== null, static function (Builder $query) use ($prefix): void {
                $query->where(static function (Builder $match) use ($prefix): void {
                    $match->where('users.name', 'like', $prefix)->orWhere('users.email', 'like', $prefix);
                });
            })
            ->when($role !== null, static function (Builder $query) use ($role): void {
                $query->role($role);
            })
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->paginate($filters['per_page'])
            ->withQueryString();
    }

    public function load(User $user): User
    {
        return $user->load(self::RELATIONS);
    }

    /** @return list<array{value: string, label: string}> */
    public function roleOptions(): array
    {
        return array_map(
            static fn (Role $role): array => ['value' => $role->value, 'label' => $role->label()],
            Role::cases(),
        );
    }

    /** @return list<array{id: int, name: string, is_active: bool}> */
    public function categoryOptions(): array
    {
        return Category::query()
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name', 'is_active'])
            ->map(static fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'is_active' => (bool) $category->is_active,
            ])
            ->values()
            ->all();
    }

    /** @return Collection<int, RoleModel> */
    public function rolesWithPermissions(): Collection
    {
        $order = array_flip(array_map(static fn (Role $role): string => $role->value, Role::cases()));

        return RoleModel::query()
            ->whereIn('name', array_keys($order))
            ->with('permissions:id,name')
            ->get(['id', 'name'])
            ->sortBy(static fn (RoleModel $role): int => $order[$role->name])
            ->values();
    }

    /** @return list<array{value: string, label: string}> */
    public function permissionOptions(): array
    {
        $seeded = PermissionModel::query()->pluck('name')->all();

        return array_values(array_map(
            static fn (Permission $permission): array => ['value' => $permission->value, 'label' => $permission->label()],
            array_filter(
                Permission::cases(),
                static fn (Permission $permission): bool => in_array($permission->value, $seeded, true),
            ),
        ));
    }
}
