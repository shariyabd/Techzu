<?php

declare(strict_types=1);

namespace App\Events\Broadcast;

use App\Enums\TicketChangeKind;
use Carbon\CarbonImmutable;

final class NotificationReceived extends RealtimeBroadcast
{
    public function __construct(public readonly int $userId, CarbonImmutable $occurredAt)
    {
        parent::__construct(TicketChangeKind::Notification, $occurredAt);
    }

    public function broadcastOn(): array
    {
        return [RealtimeChannels::user($this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'notification.received';
    }
}
