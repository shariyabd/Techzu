<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Admin\UpdateRolePermissions;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Roles\UpdatePermissionsRequest;
use App\Http\Resources\V1\RoleResource;
use App\Services\Users\UserDirectory;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class RoleController extends Controller
{
    public function __invoke(UserDirectory $directory): AnonymousResourceCollection
    {
        return RoleResource::collection($directory->rolesWithPermissions());
    }

    public function update(UpdatePermissionsRequest $request, UpdateRolePermissions $action, Role $role): RoleResource
    {
        $updated = $action->handle($role, $request->permissions());

        return RoleResource::make($updated->load('permissions:id,name'));
    }
}
