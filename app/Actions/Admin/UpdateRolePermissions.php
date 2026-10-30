<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\Permission;
use App\Enums\Role;
use App\Exceptions\Domain\CannotRemoveUserManagement;
use App\Exceptions\Domain\CustomerInternalNotesForbidden;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

final class UpdateRolePermissions
{
    public function __construct(private readonly PermissionRegistrar $registrar) {}

    /** @param  list<string>  $permissions */
    public function handle(Role $role, array $permissions): RoleModel
    {
        /** @var list<Permission> $granted */
        $granted = array_map(static fn (string $name): Permission => Permission::from($name), $permissions);

        if ($role === Role::Customer && in_array(Permission::TicketsNotesInternal, $granted, true)) {
            throw new CustomerInternalNotesForbidden;
        }

        if ($role === Role::Admin && ! in_array(Permission::UsersManage, $granted, true)) {
            throw new CannotRemoveUserManagement;
        }

        return DB::transaction(function () use ($role, $granted): RoleModel {
            /** @var RoleModel $model */
            $model = RoleModel::findOrCreate($role->value, self::guard());

            $model->syncPermissions(array_map(
                static fn (Permission $permission): string => $permission->value,
                $granted,
            ));

            $this->registrar->forgetCachedPermissions();

            return $model;
        });
    }

    private static function guard(): string
    {
        return (string) config('permission.guard', 'web');
    }
}
