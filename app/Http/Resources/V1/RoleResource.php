<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Enums\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;

/** @mixin RoleModel */
final class RoleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $role = Role::from($this->name);

        return [
            'value' => $role->value,
            'label' => $role->label(),
            'permissions' => $this->permissions
                ->map(static fn (PermissionModel $permission): string => $permission->name)
                ->values()
                ->all(),
        ];
    }
}
