<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TicketReplied;
use App\Services\Agents\AgentDirectoryVersion;

final class RotateAgentDirectoryVersion
{
    public function __construct(private readonly AgentDirectoryVersion $version) {}

    public function handle(TicketReplied $event): void
    {
        if ($event->reply->is_internal || $event->author->id === $event->ticket->customer_id) {
            return;
        }

        $this->version->rotate();
    }
}
