<?php

declare(strict_types=1);

namespace App\Events\Broadcast;

use App\Enums\TicketChangeKind;
use Carbon\CarbonImmutable;

final class StaffTicketChanged extends RealtimeBroadcast
{
    public function __construct(string $ticketNumber, TicketChangeKind $kind, CarbonImmutable $occurredAt)
    {
        parent::__construct($kind, $occurredAt, $ticketNumber);
    }

    public function broadcastOn(): array
    {
        return [
            RealtimeChannels::ticketStaff((string) $this->ticketNumber),
            RealtimeChannels::queue(),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ticket.changed';
    }
}
