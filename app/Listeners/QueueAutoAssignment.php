<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TicketCreated;
use App\Jobs\AutoAssignTicket;
use Illuminate\Contracts\Config\Repository as Config;

final class QueueAutoAssignment
{
    public function __construct(
        private readonly Config $config,
    ) {}

    public function handle(TicketCreated $event): void
    {
        if ($event->imported || ! (bool) $this->config->get('tickets.auto_assign', false)) {
            return;
        }

        if ($event->ticket->assigned_agent_id !== null) {
            return;
        }

        AutoAssignTicket::dispatch($event->ticket->id);
    }
}
