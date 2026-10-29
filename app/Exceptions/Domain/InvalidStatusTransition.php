<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use App\Enums\TicketStatus;

final class InvalidStatusTransition extends DomainException
{
    public static function between(TicketStatus $from, TicketStatus $to): self
    {
        return new self(sprintf('A ticket cannot move from %s to %s.', $from->label(), $to->label()));
    }

    public function status(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'invalid_status_transition';
    }
}
