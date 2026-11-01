<?php

declare(strict_types=1);

use App\Enums\TicketChangeKind;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Events\Broadcast\InternalNoteAdded;
use App\Events\Broadcast\NotificationReceived;
use App\Events\Broadcast\RealtimeBroadcast;
use App\Events\Broadcast\StaffTicketChanged;
use App\Events\Broadcast\TicketChanged;
use App\Events\TicketCreated;
use App\Events\TicketPriorityChanged;
use App\Events\TicketSlaApproaching;
use App\Events\TicketSlaBreached;
use App\Events\TicketSlaRecalculated;
use App\Events\TicketStatusChanged;
use App\Events\TicketUpdated;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

const BROADCAST_EVENTS = [TicketChanged::class, InternalNoteAdded::class, StaffTicketChanged::class, NotificationReceived::class];

/** @return list<string> */
function channelNames(RealtimeBroadcast $event): array
{
    return array_map(static fn (PrivateChannel $channel): string => $channel->name, $event->broadcastOn());
}

function expectSafePayload(RealtimeBroadcast $event, Ticket $ticket): void
{
    $payload = $event->broadcastWith();
    $json = (string) json_encode($payload);

    expect(array_keys($payload))->toBe(['ticket', 'kind', 'at'])
        ->and($payload)->not->toHaveKeys(['subject', 'description', 'body', 'name', 'email', 'customer', 'assigned_agent'])
        ->and($json)->not->toContain($ticket->subject)
        ->and($json)->not->toContain($ticket->customer->email);
}

beforeEach(function (): void {
    config(['tickets.auto_assign' => false]);
    $this->customer = User::factory()->customer()->create();
    $this->agent = User::factory()->agent()->create();
    $this->ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($this->agent)->create([
        'customer_id' => $this->customer->id,
        'created_by_id' => $this->customer->id,
        'subject' => 'Printer on fire in accounting',
    ]);
});

it('broadcasts identifiers only, on the ticket, queue and customer channels, for every customer-safe domain event', function (Closure $dispatch, TicketChangeKind $kind): void {
    Event::fake(BROADCAST_EVENTS);

    $dispatch($this->ticket, $this->agent);

    Event::assertDispatched(TicketChanged::class, function (TicketChanged $event) use ($kind): bool {
        expect(channelNames($event))->toBe(["private-tickets.{$this->ticket->number}", 'private-tickets.queue', "private-App.Models.User.{$this->customer->id}"]);
        expectSafePayload($event, $this->ticket);

        return $event->kind === $kind && $event->broadcastWith()['ticket'] === $this->ticket->number;
    });
    Event::assertNotDispatched(InternalNoteAdded::class);
})->with([
    'created' => [fn (Ticket $t, User $a) => TicketCreated::dispatch($t, $a), TicketChangeKind::Created],
    'updated' => [fn (Ticket $t, User $a) => TicketUpdated::dispatch($t, $a, ['subject' => ['from' => 'Old secret', 'to' => $t->subject]]), TicketChangeKind::Updated],
    'status changed' => [fn (Ticket $t, User $a) => TicketStatusChanged::dispatch($t, $a, TicketStatus::Open, TicketStatus::InProgress), TicketChangeKind::StatusChanged],
    'priority changed' => [fn (Ticket $t, User $a) => TicketPriorityChanged::dispatch($t, $a, TicketPriority::Low, TicketPriority::High), TicketChangeKind::PriorityChanged],
    'sla recalculated by an admin' => [fn (Ticket $t) => TicketSlaRecalculated::dispatch($t), TicketChangeKind::Updated],
]);

it('broadcasts an internal note only on the staff channel and never on the customer channel', function (): void {
    Event::fake(BROADCAST_EVENTS);
    Sanctum::actingAs($this->agent, ['*']);

    $this->postJson(route('api.v1.tickets.replies.store', $this->ticket), ['body' => 'Customer is on the watch list', 'is_internal' => true])->assertCreated();

    Event::assertNotDispatched(NotificationReceived::class, fn (NotificationReceived $event): bool => in_array("private-App.Models.User.{$this->customer->id}", channelNames($event), true));

    Event::assertDispatched(InternalNoteAdded::class, function (InternalNoteAdded $event): bool {
        expect(channelNames($event))->toBe(["private-tickets.{$this->ticket->number}.staff"])
            ->and((string) json_encode($event->broadcastWith()))->not->toContain('watch list');

        return true;
    });
    Event::assertNotDispatched(TicketChanged::class);
});

it('announces SLA escalations only to staff, never on the customer ticket or user channels', function (Closure $dispatch, TicketChangeKind $kind): void {
    Event::fake(BROADCAST_EVENTS);

    $dispatch($this->ticket);

    Event::assertDispatched(StaffTicketChanged::class, function (StaffTicketChanged $event) use ($kind): bool {
        expect(channelNames($event))->toBe(["private-tickets.{$this->ticket->number}.staff", 'private-tickets.queue']);
        expectSafePayload($event, $this->ticket);

        return $event->kind === $kind;
    });
    Event::assertNotDispatched(TicketChanged::class);
})->with([
    'sla approaching' => [fn (Ticket $t) => TicketSlaApproaching::dispatch($t), TicketChangeKind::SlaApproaching],
    'sla breached' => [fn (Ticket $t) => TicketSlaBreached::dispatch($t), TicketChangeKind::SlaBreached],
]);

it('broadcasts a public reply on the ticket channel and never on the staff channel', function (): void {
    Event::fake(BROADCAST_EVENTS);
    Sanctum::actingAs($this->agent, ['*']);

    $this->postJson(route('api.v1.tickets.replies.store', $this->ticket), ['body' => 'We are on it'])->assertCreated();

    Event::assertDispatched(TicketChanged::class, fn (TicketChanged $event): bool => $event->kind === TicketChangeKind::Replied
        && ! in_array("private-tickets.{$this->ticket->number}.staff", channelNames($event), true));
    Event::assertNotDispatched(InternalNoteAdded::class);
});

it('does not broadcast every imported row, so a large import cannot flood the queue', function (): void {
    Event::fake(BROADCAST_EVENTS);

    TicketCreated::dispatch($this->ticket, $this->agent, true);

    Event::assertNotDispatched(TicketChanged::class);
});

it('pings the recipient\'s private channel when a notification lands, without its content', function (): void {
    Event::fake(BROADCAST_EVENTS);
    Sanctum::actingAs($this->agent, ['*']);

    $this->postJson(route('api.v1.tickets.replies.store', $this->ticket), ['body' => 'Secret fix details'])->assertCreated();

    Event::assertDispatched(NotificationReceived::class, function (NotificationReceived $event): bool {
        expect(channelNames($event))->toBe(["private-App.Models.User.{$this->customer->id}"])
            ->and((string) json_encode($event->broadcastWith()))->not->toContain('Secret fix');

        return true;
    });
    Event::assertNotDispatched(NotificationReceived::class, fn (NotificationReceived $event): bool => $event->userId === $this->agent->id);
});
