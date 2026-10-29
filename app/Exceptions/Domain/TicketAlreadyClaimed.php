<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

final class TicketAlreadyClaimed extends DomainException
{
    public static function for(string $ticketNumber): self
    {
        return new self(sprintf('Ticket %s has already been claimed by another agent.', $ticketNumber));
    }

    public function status(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'ticket_already_claimed';
    }
}
