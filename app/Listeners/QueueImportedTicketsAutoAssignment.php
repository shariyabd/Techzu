<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TicketImportFinished;
use App\Jobs\AutoAssignImportedTickets;
use Illuminate\Contracts\Config\Repository as Config;

final class QueueImportedTicketsAutoAssignment
{
    public function __construct(
        private readonly Config $config,
    ) {}

    public function handle(TicketImportFinished $event): void
    {
        if (! $event->import->auto_assign || ! (bool) $this->config->get('tickets.auto_assign', false)) {
            return;
        }

        AutoAssignImportedTickets::dispatch($event->import->id);
    }
}
