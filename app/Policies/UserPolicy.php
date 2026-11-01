<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Access\Response;

final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::UsersManage->value);
    }

    public function view(User $user, User $model): bool
    {
        return $user->can(Permission::UsersManage->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::UsersManage->value);
    }

    public function update(User $user, User $model): bool
    {
        return $user->can(Permission::UsersManage->value);
    }

    public function delete(User $user, User $model): bool
    {
        return $user->can(Permission::UsersManage->value) && $model->isNot($user);
    }

    public function lookupCustomers(User $user): bool
    {
        return $user->can(Permission::TicketsViewAny->value)
            || $user->can(Permission::TicketsCreateOnBehalf->value);
    }

    public function managePermissions(User $user): bool
    {
        return $user->can(Permission::UsersManage->value);
    }

    public function viewAgentDirectory(User $user): bool
    {
        return $user->can(Permission::TicketsViewAny->value);
    }

    public function viewAgentProfile(User $user, User $model): Response
    {
        if (! $this->viewAgentDirectory($user)) {
            return Response::deny();
        }

        return $model->hasAnyRole([Role::Agent->value, Role::Admin->value])
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function viewAvatar(User $viewer, User $subject): Response
    {
        if ($viewer->is($subject) || $viewer->can(Permission::TicketsViewAny->value)) {
            return Response::allow();
        }

        return $viewer->can(Permission::TicketsViewOwn->value) && $subject->hasAnyRole([Role::Agent->value, Role::Admin->value])
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
