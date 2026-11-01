<?php

declare(strict_types=1);

use App\Actions\Assignment\AssignTicket;
use App\Enums\AssignmentMethod;
use App\Enums\TicketStatus;
use App\Events\TicketAssigned;
use App\Events\TicketStatusChanged;
use App\Exceptions\Domain\AgentNotEligible;
use App\Exceptions\Domain\TicketClosed;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    Event::fake([TicketAssigned::class, TicketStatusChanged::class]);
    $this->now = CarbonImmutable::parse('2026-11-01 03:00:00', 'UTC');
    $this->travelTo($this->now);
    $this->category = Category::factory()->create();
    $this->admin = User::factory()->admin()->create();
    $this->agent = User::factory()->agent([$this->category->id])->create(['last_assigned_at' => null]);
});

it('assigns an open ticket and moves it to in progress like a claim', function (): void {
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['assigned_agent_id' => null, 'category_id' => $this->category->id]);

    $result = app(AssignTicket::class)->handle($this->admin, $ticket, $this->agent);

    expect($result->assigned_agent_id)->toBe($this->agent->id)
        ->and($ticket->fresh()?->status)->toBe(TicketStatus::InProgress)
        ->and($result->assigned_at?->equalTo($this->now))->toBeTrue()
        ->and($this->agent->fresh()?->last_assigned_at?->equalTo($this->now))->toBeTrue();
    Event::assertDispatched(TicketAssigned::class, fn (TicketAssigned $e): bool => $e->actor?->is($this->admin) === true
        && $e->agent->is($this->agent)
        && $e->previousAgent === null
        && $e->method === AssignmentMethod::Manual);
    Event::assertDispatched(TicketStatusChanged::class, fn (TicketStatusChanged $e): bool => $e->actor?->is($this->admin) === true
        && $e->from === TicketStatus::Open
        && $e->to === TicketStatus::InProgress);
});

it('keeps any other active status when assigning', function (TicketStatus $status): void {
    $ticket = Ticket::factory()->status($status)->create(['assigned_agent_id' => null, 'category_id' => $this->category->id]);

    app(AssignTicket::class)->handle($this->admin, $ticket, $this->agent);

    expect($ticket->fresh()?->status)->toBe($status);
    Event::assertNotDispatched(TicketStatusChanged::class);
})->with([TicketStatus::InProgress, TicketStatus::WaitingForCustomer]);

it('reassigns and reports the previous agent', function (): void {
    $previous = User::factory()->agent()->create();
    $ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($previous)->create(['category_id' => $this->category->id]);

    app(AssignTicket::class)->handle($this->admin, $ticket, $this->agent);

    expect($ticket->fresh()?->assigned_agent_id)->toBe($this->agent->id);
    Event::assertDispatched(TicketAssigned::class, fn (TicketAssigned $e): bool => $e->previousAgent?->is($previous) === true);
});

it('lets an agent hand off their own ticket', function (): void {
    $owner = User::factory()->agent()->create();
    $ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($owner)->create(['category_id' => $this->category->id]);

    app(AssignTicket::class)->handle($owner, $ticket, $this->agent);

    expect($ticket->fresh()?->assigned_agent_id)->toBe($this->agent->id);
    Event::assertDispatched(TicketAssigned::class, fn (TicketAssigned $e): bool => $e->actor?->is($owner) === true
        && $e->previousAgent?->is($owner) === true);
});

it('refuses a stale hand-off when an admin reassigned the ticket between the check and the write', function (): void {
    $owner = User::factory()->agent()->create();
    $reassignedTo = User::factory()->agent([$this->category->id])->create();
    $ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($owner)->create(['category_id' => $this->category->id]);
    expect($owner->can('assign', $ticket))->toBeTrue();
    DB::table('tickets')->where('id', $ticket->id)->update(['assigned_agent_id' => $reassignedTo->id]);

    expect(fn () => app(AssignTicket::class)->handle($owner, $ticket, $this->agent))
        ->toThrow(AuthorizationException::class);

    expect($ticket->fresh()?->assigned_agent_id)->toBe($reassignedTo->id);
    Event::assertNotDispatched(TicketAssigned::class);
});

it('rejects targets that are not eligible agents for the ticket', function (Closure $target, bool $outsideCategory): void {
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['assigned_agent_id' => null, 'category_id' => $this->category->id]);

    expect(fn () => app(AssignTicket::class)->handle($this->admin, $ticket, $target($this->category), $outsideCategory))
        ->toThrow(AgentNotEligible::class);

    expect($ticket->fresh())->assigned_agent_id->toBeNull()->status->toBe(TicketStatus::Open);
    Event::assertNotDispatched(TicketAssigned::class);
})->with([
    'agent outside the category' => [fn () => User::factory()->agent([Category::factory()->create()->id])->create(), false],
    'agent with no categories' => [fn () => User::factory()->agent()->create(), false],
    'unavailable agent in the category' => [fn (Category $c) => User::factory()->agent([$c->id])->unavailable()->create(), false],
    'unavailable agent even with the override' => [fn () => User::factory()->agent()->unavailable()->create(), true],
    'customer even with the override' => [fn () => User::factory()->customer()->create(), true],
    'admin even with the override' => [fn () => User::factory()->admin()->create(), true],
]);

it('assigns an available agent outside the category only when explicitly overridden', function (): void {
    $outsider = User::factory()->agent([Category::factory()->create()->id])->create();
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['assigned_agent_id' => null, 'category_id' => $this->category->id]);

    app(AssignTicket::class)->handle($this->admin, $ticket, $outsider, outsideCategory: true);

    expect($ticket->fresh())->assigned_agent_id->toBe($outsider->id)->status->toBe(TicketStatus::InProgress);
});

it('is a no-op when the agent already owns the ticket', function (): void {
    $ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($this->agent)->create();

    app(AssignTicket::class)->handle($this->admin, $ticket, $this->agent);

    Event::assertNotDispatched(TicketAssigned::class);
});

it('refuses to assign a finished ticket', function (TicketStatus $finished): void {
    $ticket = Ticket::factory()->status($finished)->create(['assigned_agent_id' => null]);

    expect(fn () => app(AssignTicket::class)->handle($this->admin, $ticket, $this->agent))
        ->toThrow(TicketClosed::class);
    expect($ticket->fresh()?->assigned_agent_id)->toBeNull();
    Event::assertNotDispatched(TicketAssigned::class);
})->with([TicketStatus::Resolved, TicketStatus::Closed]);
