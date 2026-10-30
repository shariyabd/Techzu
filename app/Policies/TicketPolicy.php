<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\Response;

final class TicketPolicy
{
    private const array CUSTOMER_TARGETS_FROM_RESOLVED = [TicketStatus::Closed, TicketStatus::InProgress];

    public function viewAny(User $user): bool
    {
        return $this->has($user, Permission::TicketsViewAny)
            || $this->has($user, Permission::TicketsViewOwn);
    }

    public function view(User $user, Ticket $ticket): Response
    {
        return $this->decide($user, $ticket, static fn (): bool => true);
    }

    public function create(User $user): bool
    {
        return $this->has($user, Permission::TicketsCreate);
    }

    public function createOnBehalf(User $user): bool
    {
        return $this->has($user, Permission::TicketsCreateOnBehalf);
    }

    public function update(User $user, Ticket $ticket): Response
    {
        return $this->decide($user, $ticket, fn (): bool => $this->manages($user, $ticket)
            || ($this->isCustomerOf($user, $ticket)
                && $this->has($user, Permission::TicketsUpdateOwn)
                && $ticket->status === TicketStatus::Open));
    }

    public function changeStatus(User $user, Ticket $ticket, ?TicketStatus $to = null): Response
    {
        return $this->decide($user, $ticket, fn (): bool => $this->manages($user, $ticket)
            || ($this->isCustomerOf($user, $ticket)
                && $this->has($user, Permission::TicketsUpdateOwn)
                && $ticket->status === TicketStatus::Resolved
                && ($to === null || in_array($to, self::CUSTOMER_TARGETS_FROM_RESOLVED, true))));
    }

    public function changePriority(User $user, Ticket $ticket): Response
    {
        return $this->decide($user, $ticket, fn (): bool => $this->manages($user, $ticket));
    }

    public function claim(User $user, Ticket $ticket): Response
    {
        return $this->decide($user, $ticket, fn (): bool => $this->has($user, Permission::TicketsClaim)
            && $ticket->status->isActive());
    }

    public function assign(User $user, Ticket $ticket): Response
    {
        return $this->decide($user, $ticket, fn (): bool => $ticket->status->isActive()
            && ($this->has($user, Permission::TicketsAssignAny)
                || ($this->has($user, Permission::TicketsHandoffOwn) && $this->isAssigneeOf($user, $ticket))));
    }

    public function reply(User $user, Ticket $ticket): Response
    {
        return $this->decide($user, $ticket, fn (): bool => $this->has($user, Permission::TicketsReply)
            && $ticket->status !== TicketStatus::Closed);
    }

    public function addInternalNote(User $user, Ticket $ticket): Response
    {
        return $this->decide($user, $ticket, fn (): bool => $this->has($user, Permission::TicketsNotesInternal)
            && $ticket->status !== TicketStatus::Closed);
    }

    public function viewInternal(User $user, Ticket $ticket): Response
    {
        return $this->decide($user, $ticket, fn (): bool => $this->has($user, Permission::TicketsNotesInternal));
    }

    /** @param Closure(): bool $rule */
    private function decide(User $user, Ticket $ticket, Closure $rule): Response
    {
        if (! $this->canSee($user, $ticket)) {
            return Response::denyAsNotFound();
        }

        return $rule() ? Response::allow() : Response::deny();
    }

    private function canSee(User $user, Ticket $ticket): bool
    {
        return $this->has($user, Permission::TicketsViewAny)
            || ($this->has($user, Permission::TicketsViewOwn) && $this->isCustomerOf($user, $ticket));
    }

    private function manages(User $user, Ticket $ticket): bool
    {
        return $this->has($user, Permission::TicketsUpdateAny)
            || ($this->has($user, Permission::TicketsUpdateAssigned) && $this->isAssigneeOf($user, $ticket));
    }

    private function isCustomerOf(User $user, Ticket $ticket): bool
    {
        return $ticket->customer_id === $user->id;
    }

    private function isAssigneeOf(User $user, Ticket $ticket): bool
    {
        return $ticket->assigned_agent_id === $user->id;
    }

    private function has(User $user, Permission $permission): bool
    {
        return $user->can($permission->value);
    }
}
