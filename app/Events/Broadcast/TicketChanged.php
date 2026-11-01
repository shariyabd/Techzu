<?php

declare(strict_types=1);

namespace App\Events\Broadcast;

use App\Enums\TicketChangeKind;
use Carbon\CarbonImmutable;

final class TicketChanged extends RealtimeBroadcast
{
    public function __construct(string $ticketNumber, public readonly int $customerId, TicketChangeKind $kind, CarbonImmutable $occurredAt)
    {
        parent::__construct($kind, $occurredAt, $ticketNumber);
    }

    public function broadcastOn(): array
    {
        return [
            RealtimeChannels::ticket((string) $this->ticketNumber),
            RealtimeChannels::queue(),
            RealtimeChannels::user($this->customerId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ticket.changed';
    }
}
