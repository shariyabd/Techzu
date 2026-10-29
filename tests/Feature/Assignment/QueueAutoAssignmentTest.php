<?php

declare(strict_types=1);

use App\Actions\Tickets\CreateTicket;
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

it('does not queue auto-assignment', function (bool $imported, bool $enabled): void {
    config()->set('tickets.auto_assign', $enabled);
    $ticket = Ticket::factory()->create(['assigned_agent_id' => null]);

    TicketCreated::dispatch($ticket, null, $imported);

    Queue::assertNotPushed(AutoAssignTicket::class);
})->with([
    'for imported tickets' => [true, true],
    'when switched off' => [false, false],
]);
