<?php

declare(strict_types=1);

use App\Actions\Assignment\AssignTicket;
use App\Enums\AssignmentMethod;
use App\Enums\TicketStatus;
use App\Events\TicketAssigned;
use App\Exceptions\Domain\AgentNotEligible;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    Event::fake([TicketAssigned::class]);
    $this->now = CarbonImmutable::parse('2026-11-01 03:00:00', 'UTC');
    $this->travelTo($this->now);
    $this->admin = User::factory()->admin()->create();
    $this->agent = User::factory()->agent()->create(['last_assigned_at' => null]);
});

it('assigns an unassigned ticket without changing its status', function (): void {
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['assigned_agent_id' => null]);

    $result = app(AssignTicket::class)->handle($this->admin, $ticket, $this->agent);

    expect($result->assigned_agent_id)->toBe($this->agent->id)
        ->and($result->status)->toBe(TicketStatus::Open)
        ->and($result->assigned_at?->equalTo($this->now))->toBeTrue()
        ->and($this->agent->fresh()?->last_assigned_at?->equalTo($this->now))->toBeTrue();
    Event::assertDispatched(TicketAssigned::class, fn (TicketAssigned $e): bool => $e->actor?->is($this->admin) === true
        && $e->agent->is($this->agent)
        && $e->previousAgent === null
        && $e->method === AssignmentMethod::Manual);
});

it('reassigns and reports the previous agent', function (): void {
    $previous = User::factory()->agent()->create();
    $ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($previous)->create();

    app(AssignTicket::class)->handle($this->admin, $ticket, $this->agent);

    expect($ticket->fresh()?->assigned_agent_id)->toBe($this->agent->id);
    Event::assertDispatched(TicketAssigned::class, fn (TicketAssigned $e): bool => $e->previousAgent?->is($previous) === true);
});

it('lets an agent hand off their own ticket', function (): void {
    $owner = User::factory()->agent()->create();
    $ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($owner)->create();

    app(AssignTicket::class)->handle($owner, $ticket, $this->agent);

    expect($ticket->fresh()?->assigned_agent_id)->toBe($this->agent->id);
    Event::assertDispatched(TicketAssigned::class, fn (TicketAssigned $e): bool => $e->actor?->is($owner) === true
        && $e->previousAgent?->is($owner) === true);
});

it('rejects targets that are not available agents', function (Closure $target): void {
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['assigned_agent_id' => null]);

    expect(fn () => app(AssignTicket::class)->handle($this->admin, $ticket, $target()))
        ->toThrow(AgentNotEligible::class);

    expect($ticket->fresh()?->assigned_agent_id)->toBeNull();
    Event::assertNotDispatched(TicketAssigned::class);
})->with([
    'unavailable agent' => [fn () => User::factory()->agent()->unavailable()->create()],
    'customer' => [fn () => User::factory()->customer()->create()],
    'admin' => [fn () => User::factory()->admin()->create()],
]);

it('is a no-op when the agent already owns the ticket', function (): void {
    $ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($this->agent)->create();

    app(AssignTicket::class)->handle($this->admin, $ticket, $this->agent);

    Event::assertNotDispatched(TicketAssigned::class);
});
