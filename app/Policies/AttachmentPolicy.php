<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Attachment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

final class AttachmentPolicy
{
    public function __construct(private readonly TicketPolicy $tickets) {}

    public function view(User $user, Attachment $attachment): Response
    {
        $ticket = $attachment->loadMissing('ticket')->ticket;
        $ticketAccess = $this->tickets->view($user, $ticket);

        if ($ticketAccess->denied()) {
            return $ticketAccess;
        }

        if ($attachment->is_internal && $this->tickets->viewInternal($user, $ticket)->denied()) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }
}
