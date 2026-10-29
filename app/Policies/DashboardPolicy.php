<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

final class DashboardPolicy
{
    public function view(User $user): bool
    {
        return $user->can(Permission::DashboardView->value);
    }
}
