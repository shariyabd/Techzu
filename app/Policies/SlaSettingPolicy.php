<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

final class SlaSettingPolicy
{
    public function view(User $user): bool
    {
        return $user->can(Permission::SlaManage->value);
    }

    public function update(User $user): bool
    {
        return $user->can(Permission::SlaManage->value);
    }
}
