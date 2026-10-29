<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

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
}
