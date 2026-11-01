<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\TicketChangeKind;
use App\Events\Broadcast\InternalNoteAdded;
use App\Events\Broadcast\StaffTicketChanged;
use App\Events\Broadcast\TicketChanged;
use App\Events\Broadcast\TicketQueueChanged;
use App\Events\TicketAssigned;
use App\Events\TicketCreated;
use App\Events\TicketImportFinished;
use App\Events\TicketPriorityChanged;
use App\Events\TicketReplied;
use App\Events\TicketSlaApproaching;
use App\Events\TicketSlaBreached;
use App\Events\TicketSlaRecalculated;
use App\Events\TicketStatusChanged;
use App\Events\TicketUnassigned;
use App\Events\TicketUpdated;
use App\Models\Ticket;
use Carbon\CarbonImmutable;

final class BroadcastTicketChanges
{
    public function handleTicketCreated(TicketCreated $event): void
    {
        if ($event->imported) {
            return;
        }

        $this->changed($event->ticket, TicketChangeKind::Created);
    }

    public function handleTicketUpdated(TicketUpdated $event): void
    {
        $this->changed($event->ticket, TicketChangeKind::Updated);
    }

    public function handleTicketReplied(TicketReplied $event): void
    {
        if ($event->reply->is_internal) {
            InternalNoteAdded::dispatch($event->ticket->number, CarbonImmutable::now());

            return;
        }

        $this->changed($event->ticket, TicketChangeKind::Replied);
    }

    public function handleTicketStatusChanged(TicketStatusChanged $event): void
    {
        $this->changed($event->ticket, TicketChangeKind::StatusChanged);
    }

    public function handleTicketPriorityChanged(TicketPriorityChanged $event): void
    {
        $this->changed($event->ticket, TicketChangeKind::PriorityChanged);
    }

    public function handleTicketAssigned(TicketAssigned $event): void
    {
        $this->changed($event->ticket, TicketChangeKind::Assigned);
    }

    public function handleTicketUnassigned(TicketUnassigned $event): void
    {
        $this->changed($event->ticket, TicketChangeKind::Unassigned);
    }

    public function handleTicketSlaApproaching(TicketSlaApproaching $event): void
    {
        StaffTicketChanged::dispatch($event->ticket->number, TicketChangeKind::SlaApproaching, CarbonImmutable::now());
    }

    public function handleTicketSlaBreached(TicketSlaBreached $event): void
    {
        StaffTicketChanged::dispatch($event->ticket->number, TicketChangeKind::SlaBreached, CarbonImmutable::now());
    }

    public function handleTicketSlaRecalculated(TicketSlaRecalculated $event): void
    {
        $this->changed($event->ticket, TicketChangeKind::Updated);
    }

    public function handleTicketImportFinished(TicketImportFinished $event): void
    {
        TicketQueueChanged::dispatch(TicketChangeKind::ImportFinished, CarbonImmutable::now());
    }

    private function changed(Ticket $ticket, TicketChangeKind $kind): void
    {
        TicketChanged::dispatch($ticket->number, $ticket->customer_id, $kind, CarbonImmutable::now());
    }
}
