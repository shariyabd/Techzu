<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

final class TicketClosed extends DomainException
{
    public function __construct(string $message = 'This ticket is closed and no longer accepts changes.')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'ticket_closed';
    }
}
