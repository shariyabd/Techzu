<?php

declare(strict_types=1);

use App\Actions\Tickets\UpdateTicket;
use App\Enums\TicketStatus;
use App\Events\TicketUpdated;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    Event::fake([TicketUpdated::class]);
    $this->admin = User::factory()->admin()->create();
});

it('updates subject, description and category and reports the changes', function (): void {
    $old = Category::factory()->create();
    $new = Category::factory()->create();
    $ticket = Ticket::factory()->create([
        'subject' => 'Old subject',
        'description' => 'Old description',
        'category_id' => $old->id,
    ]);

    $result = app(UpdateTicket::class)->handle($this->admin, $ticket, [
        'subject' => 'New subject',
        'description' => 'Old description',
        'category_id' => $new->id,
    ]);

    expect($result->subject)->toBe('New subject')
        ->and($ticket->fresh()?->category_id)->toBe($new->id);

    Event::assertDispatched(TicketUpdated::class, fn (TicketUpdated $e): bool => $e->changes === [
        'subject' => ['from' => 'Old subject', 'to' => 'New subject'],
        'category_id' => ['from' => $old->id, 'to' => $new->id],
    ] && $e->actor->is($this->admin));
});

it('ignores keys it does not own', function (): void {
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['subject' => 'Same']);

    app(UpdateTicket::class)->handle($this->admin, $ticket, [
        'subject' => 'Same',
        'status' => TicketStatus::Closed->value,
        'assigned_agent_id' => $this->admin->id,
        'sla_due_at' => now()->addYear()->toDateTimeString(),
    ]);

    $fresh = $ticket->fresh();
    expect($fresh?->status)->toBe(TicketStatus::Open)
        ->and($fresh?->assigned_agent_id)->toBeNull();
    Event::assertNotDispatched(TicketUpdated::class);
});

it('does nothing when no field changed', function (): void {
    $ticket = Ticket::factory()->create(['subject' => 'A', 'description' => 'B']);

    app(UpdateTicket::class)->handle($this->admin, $ticket, ['subject' => 'A', 'description' => 'B']);

    Event::assertNotDispatched(TicketUpdated::class);
});

it('refuses a customer edit when the row is no longer open even though the passed model still says open', function (): void {
    $customer = User::factory()->customer()->create();
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['customer_id' => $customer->id, 'subject' => 'Before']);
    Ticket::query()->whereKey($ticket->id)->update(['status' => TicketStatus::InProgress->value]);

    expect(fn () => app(UpdateTicket::class)->handle($customer, $ticket, ['subject' => 'After']))
        ->toThrow(AuthorizationException::class);

    expect($ticket->fresh()?->subject)->toBe('Before');
    Event::assertNotDispatched(TicketUpdated::class);
});
