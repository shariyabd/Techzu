<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', static fn (User $user, string $id): bool => (string) $user->id === $id);

Broadcast::channel('tickets.queue', static fn (User $user): bool => $user->can(Permission::TicketsViewAny->value));

Broadcast::channel('tickets.{ticket}.staff', static fn (User $user, Ticket $ticket): bool => $user->can('viewInternal', $ticket));

Broadcast::channel('tickets.{ticket}', static fn (User $user, Ticket $ticket): bool => $user->can('view', $ticket));

Broadcast::channel('presence.tickets.{ticket}', static function (User $user, Ticket $ticket): array|false {
    if (! $user->can('view', $ticket)) {
        return false;
    }

    return [
        'id' => $user->id,
        'name' => $user->name,
        'avatar_url' => $user->avatarUrl(),
        'role' => $ticket->customer_id === $user->id ? 'customer' : 'staff',
    ];
});
