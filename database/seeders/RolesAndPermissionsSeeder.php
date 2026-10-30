<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Permission;
use App\Enums\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

final class RolesAndPermissionsSeeder extends Seeder
{
    private function guard(): string
    {
        return (string) config('permission.guard', 'web');
    }

    public function run(PermissionRegistrar $registrar): void
    {
        $registrar->forgetCachedPermissions();
        $guard = $this->guard();

        foreach (Permission::cases() as $permission) {
            PermissionModel::findOrCreate($permission->value, $guard);
        }

        foreach (Role::cases() as $role) {
            /** @var RoleModel $model */
            $model = RoleModel::findOrCreate($role->value, $guard);

            $model->syncPermissions(array_map(
                static fn (Permission $permission): string => $permission->value,
                Permission::forRole($role),
            ));
        }

        $registrar->forgetCachedPermissions();
    }
}
