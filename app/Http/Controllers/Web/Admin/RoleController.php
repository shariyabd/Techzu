<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Actions\Admin\UpdateRolePermissions;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Roles\UpdatePermissionsRequest;
use App\Http\Resources\V1\RoleResource;
use App\Services\Users\UserDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class RoleController extends Controller
{
    public function __invoke(Request $request, UserDirectory $directory): Response
    {
        return Inertia::render('admin/roles/Index', [
            'roles' => RoleResource::collection($directory->rolesWithPermissions())->resolve($request),
            'permissions' => $directory->permissionOptions(),
        ]);
    }

    public function update(UpdatePermissionsRequest $request, UpdateRolePermissions $action, Role $role): RedirectResponse
    {
        $action->handle($role, $request->permissions());

        return to_route('admin.roles.index')->with('success', 'Role permissions updated.');
    }
}
