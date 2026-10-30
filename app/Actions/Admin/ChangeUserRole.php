<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Events\TicketUnassigned;
use App\Exceptions\Domain\CannotChangeOwnRole;
use App\Models\Ticket;
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

        return DB::transaction(function () use ($admin, $user, $role): User {
            $wasAgent = $user->getRoleNames()->contains(Role::Agent->value);
            $user->syncRoles([$role->value]);

            if ($role !== Role::Agent) {
                $user->categories()->detach();
            }

            if ($wasAgent && $role !== Role::Agent) {
                $this->releaseActiveTickets($admin, $user);
            }

            $user->tokens()->delete();

            return $user;
        });
    }

    private function releaseActiveTickets(User $admin, User $agent): void
    {
        Ticket::query()
            ->where('assigned_agent_id', $agent->getKey())
            ->whereIn('status', array_map(static fn (TicketStatus $s): string => $s->value, TicketStatus::active()))
            ->lockForUpdate()
            ->chunkById(100, function ($tickets) use ($admin, $agent): void {
                foreach ($tickets as $ticket) {
                    $ticket->assigned_agent_id = null;
                    $ticket->save();

                    TicketUnassigned::dispatch($ticket, $admin, $agent);
                }
            });
    }
}
