<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Holiday;
use App\Models\User;

final class HolidayPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::HolidaysManage->value);
    }

    public function view(User $user, Holiday $holiday): bool
    {
        return $user->can(Permission::HolidaysManage->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::HolidaysManage->value);
    }

    public function update(User $user, Holiday $holiday): bool
    {
        return $user->can(Permission::HolidaysManage->value);
    }

    public function delete(User $user, Holiday $holiday): bool
    {
        return $user->can(Permission::HolidaysManage->value);
    }
}
