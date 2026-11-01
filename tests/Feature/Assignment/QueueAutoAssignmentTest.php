<?php

declare(strict_types=1);

use App\Actions\Tickets\CreateTicket;
use App\Enums\TicketStatus;
use App\Events\TicketCreated;
use App\Jobs\AutoAssignTicket;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    config()->set('tickets.auto_assign', true);
});

it('queues auto-assignment on the default queue after CreateTicket commits', function (): void {
    $ticket = app(CreateTicket::class)->handle(User::factory()->customer()->create(), [
        'subject' => 'Printer',
        'description' => 'It jams.',
        'priority' => 'low',
        'category_id' => Category::factory()->create()->id,
    ]);

    Queue::assertPushed(AutoAssignTicket::class, 1);
    Queue::assertPushedOn('default', AutoAssignTicket::class, fn (AutoAssignTicket $job): bool => $job->ticketId === $ticket->id);
});

it('leaves imported active tickets to the import-level assignment instead of queueing one job per ticket', function (): void {
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['assigned_agent_id' => null]);

    TicketCreated::dispatch($ticket, null, true);

    Queue::assertNotPushed(AutoAssignTicket::class);
});

it('does not queue auto-assignment', function (TicketStatus $status, bool $enabled): void {
    config()->set('tickets.auto_assign', $enabled);
    $ticket = Ticket::factory()->status($status)->create(['assigned_agent_id' => null]);

    TicketCreated::dispatch($ticket, null, true);

    Queue::assertNotPushed(AutoAssignTicket::class);
})->with([
    'for imported resolved tickets' => [TicketStatus::Resolved, true],
    'for imported closed tickets' => [TicketStatus::Closed, true],
    'when switched off' => [TicketStatus::Open, false],
]);
