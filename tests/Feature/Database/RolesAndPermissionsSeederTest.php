<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/** @return list<string> */
function seededPermissionNamesOf(Role $role): array
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return RoleModel::findByName($role->value)->permissions()->pluck('name')->sort()->values()->all();
}

/** @return list<string> */
function baselinePermissionNamesOf(Role $role): array
{
    return collect(Permission::forRole($role))->map(fn (Permission $permission): string => $permission->value)->sort()->values()->all();
}

it('seeds every permission and role with the forRole baseline', function (): void {
    expect(PermissionModel::query()->pluck('name')->sort()->values()->all())
        ->toBe(collect(Permission::cases())->map(fn (Permission $permission): string => $permission->value)->sort()->values()->all())
        ->and(RoleModel::query()->pluck('name')->sort()->values()->all())->toBe(collect(Role::cases())->map(fn (Role $role): string => $role->value)->sort()->values()->all());

    foreach (Role::cases() as $role) {
        expect(seededPermissionNamesOf($role))->toBe(baselinePermissionNamesOf($role));
    }
});

it('is idempotent', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $pivotRows = array_sum(array_map(fn (Role $role): int => count(Permission::forRole($role)), Role::cases()));

    expect(PermissionModel::query()->count())->toBe(count(Permission::cases()))
        ->and(RoleModel::query()->count())->toBe(count(Role::cases()))
        ->and(DB::table('role_has_permissions')->count())->toBe($pivotRows);
});

it('restores the documented baseline when a role drifted', function (): void {
    RoleModel::findByName(Role::Agent->value)->revokePermissionTo(Permission::TicketsClaim->value);
    RoleModel::findByName(Role::Agent->value)->givePermissionTo(Permission::UsersManage->value);

    $this->seed(RolesAndPermissionsSeeder::class);

    expect(seededPermissionNamesOf(Role::Agent))->toBe(baselinePermissionNamesOf(Role::Agent));
});

it('keeps existing role assignments when re-run', function (): void {
    $user = User::factory()->create();
    $user->assignRole(Role::Agent->value);

    $this->seed(RolesAndPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($user->fresh()?->hasRole(Role::Agent->value))->toBeTrue()
        ->and($user->fresh()?->can(Permission::TicketsClaim->value))->toBeTrue();
});
