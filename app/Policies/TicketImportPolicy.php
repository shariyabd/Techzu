<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\TicketImport;
use App\Models\User;

final class TicketImportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ImportsManage->value);
    }

    public function view(User $user, TicketImport $import): bool
    {
        return $user->can(Permission::ImportsManage->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ImportsManage->value);
    }

    public function update(User $user, TicketImport $import): bool
    {
        return $user->can(Permission::ImportsManage->value);
    }

    public function delete(User $user, TicketImport $import): bool
    {
        return $user->can(Permission::ImportsManage->value);
    }
}
