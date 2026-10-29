<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TicketReply;
use App\Models\User;
use Illuminate\Auth\Access\Response;

final class TicketReplyPolicy
{
    public function __construct(private readonly TicketPolicy $tickets) {}

    public function view(User $user, TicketReply $reply): Response
    {
        $ticket = $reply->loadMissing('ticket')->ticket;
        $ticketAccess = $this->tickets->view($user, $ticket);

        if ($ticketAccess->denied()) {
            return $ticketAccess;
        }

        if ($reply->is_internal && $this->tickets->viewInternal($user, $ticket)->denied()) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }
}
