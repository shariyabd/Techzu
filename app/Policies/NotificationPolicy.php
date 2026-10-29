<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

final class NotificationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::TicketsViewOwn->value) || $user->can(Permission::TicketsViewAny->value);
    }
}
