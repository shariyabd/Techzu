<?php

declare(strict_types=1);

namespace App\Events\Broadcast;

use App\Enums\TicketChangeKind;
use Carbon\CarbonImmutable;

final class InternalNoteAdded extends RealtimeBroadcast
{
    public function __construct(string $ticketNumber, CarbonImmutable $occurredAt)
    {
        parent::__construct(TicketChangeKind::InternalNote, $occurredAt, $ticketNumber);
    }

    public function broadcastOn(): array
    {
        return [RealtimeChannels::ticketStaff((string) $this->ticketNumber)];
    }

    public function broadcastAs(): string
    {
        return 'ticket.internal-note';
    }
}
