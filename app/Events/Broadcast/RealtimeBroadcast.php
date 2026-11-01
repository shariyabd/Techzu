<?php

declare(strict_types=1);

namespace App\Events\Broadcast;

use App\Enums\TicketChangeKind;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

abstract class RealtimeBroadcast implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;

    public const string QUEUE = 'broadcasts';

    public int $tries = 3;

    public function __construct(
        public readonly TicketChangeKind $kind,
        public readonly CarbonImmutable $occurredAt,
        public readonly ?string $ticketNumber = null,
    ) {}

    /** @return list<PrivateChannel> */
    abstract public function broadcastOn(): array;

    abstract public function broadcastAs(): string;

    /** @return array{ticket: string|null, kind: string, at: string} */
    public function broadcastWith(): array
    {
        return [
            'ticket' => $this->ticketNumber,
            'kind' => $this->kind->value,
            'at' => $this->occurredAt->toIso8601String(),
        ];
    }

    public function broadcastQueue(): string
    {
        return self::QUEUE;
    }
}
