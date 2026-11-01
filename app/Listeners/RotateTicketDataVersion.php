<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TicketAssigned;
use App\Events\TicketCreated;
use App\Events\TicketPriorityChanged;
use App\Events\TicketSlaApproaching;
use App\Events\TicketSlaBreached;
use App\Events\TicketSlaRecalculated;
use App\Events\TicketStatusChanged;
use App\Events\TicketUnassigned;
use App\Events\TicketUpdated;
use App\Services\Tickets\TicketDataVersion;

final class RotateTicketDataVersion
{
    public function __construct(private readonly TicketDataVersion $version) {}

    public function handle(TicketCreated|TicketStatusChanged|TicketAssigned|TicketUnassigned|TicketPriorityChanged|TicketSlaApproaching|TicketSlaBreached|TicketSlaRecalculated $event): void
    {
        $this->rotate();
    }

    public function handleTicketUpdated(TicketUpdated $event): void
    {
        if (array_key_exists('category_id', $event->changes)) {
            $this->rotate();
        }
    }

    private function rotate(): void
    {
        $this->version->rotate();
    }
}
