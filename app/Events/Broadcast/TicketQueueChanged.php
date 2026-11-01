<?php

declare(strict_types=1);

namespace App\Events\Broadcast;

use App\Enums\TicketChangeKind;
use Carbon\CarbonImmutable;

final class TicketQueueChanged extends RealtimeBroadcast
{
    public function __construct(TicketChangeKind $kind, CarbonImmutable $occurredAt)
    {
        parent::__construct($kind, $occurredAt);
    }

    public function broadcastOn(): array
    {
        return [RealtimeChannels::queue()];
    }

    public function broadcastAs(): string
    {
        return 'queue.changed';
    }
}
