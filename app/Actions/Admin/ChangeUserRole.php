<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\Role;
use App\Exceptions\Domain\CannotChangeOwnRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ChangeUserRole
{
    public function handle(User $admin, User $user, Role $role): User
    {
        if ($user->getRoleNames()->all() === [$role->value]) {
            return $user;
        }

        if ($admin->is($user)) {
            throw new CannotChangeOwnRole;
        }

        return DB::transaction(function () use ($user, $role): User {
            $user->syncRoles([$role->value]);

            if ($role !== Role::Agent) {
                $user->categories()->detach();
            }

            $user->tokens()->delete();

            return $user;
        });
    }
}
