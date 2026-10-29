<?php

declare(strict_types=1);

use App\Actions\Assignment\ClaimTicket;
use App\Enums\AssignmentMethod;
use App\Enums\TicketStatus;
use App\Events\TicketAssigned;
use App\Events\TicketStatusChanged;
use App\Exceptions\Domain\TicketAlreadyClaimed;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->now = CarbonImmutable::parse('2026-11-01 03:00:00', 'UTC');
    $this->travelTo($this->now);
    $this->agent = User::factory()->agent()->create(['last_assigned_at' => null]);
});

it('claims an unassigned open ticket and moves it to in progress', function (): void {
    Event::fake([TicketAssigned::class, TicketStatusChanged::class]);
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['assigned_agent_id' => null]);

    $result = app(ClaimTicket::class)->handle($this->agent, $ticket);

    expect($result->assigned_agent_id)->toBe($this->agent->id)
        ->and($result->status)->toBe(TicketStatus::InProgress)
        ->and($result->assigned_at?->equalTo($this->now))->toBeTrue()
        ->and($this->agent->fresh()?->last_assigned_at?->equalTo($this->now))->toBeTrue();
    Event::assertDispatched(TicketAssigned::class, fn (TicketAssigned $e): bool => $e->ticket->is($ticket)
        && $e->actor?->is($this->agent) === true
        && $e->agent->is($this->agent)
        && $e->previousAgent === null
        && $e->method === AssignmentMethod::Claimed);
    Event::assertDispatched(TicketStatusChanged::class, fn (TicketStatusChanged $e): bool => $e->from === TicketStatus::Open
        && $e->to === TicketStatus::InProgress);
});

it('keeps a non-open status when claiming', function (): void {
    Event::fake([TicketAssigned::class, TicketStatusChanged::class]);
    $ticket = Ticket::factory()->status(TicketStatus::WaitingForCustomer)->create(['assigned_agent_id' => null]);

    $result = app(ClaimTicket::class)->handle($this->agent, $ticket);

    expect($result->status)->toBe(TicketStatus::WaitingForCustomer);
    Event::assertDispatched(TicketAssigned::class, 1);
    Event::assertNotDispatched(TicketStatusChanged::class);
});

it('throws TicketAlreadyClaimed when the ticket already has an agent', function (): void {
    Event::fake([TicketAssigned::class]);
    $owner = User::factory()->agent()->create();
    $ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($owner)->create();

    expect(fn () => app(ClaimTicket::class)->handle($this->agent, $ticket))
        ->toThrow(TicketAlreadyClaimed::class);

    expect($ticket->fresh()?->assigned_agent_id)->toBe($owner->id)
        ->and($this->agent->fresh()?->last_assigned_at)->toBeNull();
    Event::assertNotDispatched(TicketAssigned::class);
});

it('refuses to claim a ticket finished after authorization', function (TicketStatus $finished): void {
    Event::fake([TicketAssigned::class]);
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['assigned_agent_id' => null]);
    $stale = Ticket::query()->findOrFail($ticket->id);
    Ticket::query()->whereKey($ticket->id)->update(['status' => $finished->value]);

    expect(fn () => app(ClaimTicket::class)->handle($this->agent, $stale))
        ->toThrow(TicketAlreadyClaimed::class);

    expect($ticket->fresh()?->assigned_agent_id)->toBeNull()
        ->and($ticket->fresh()?->status)->toBe($finished)
        ->and($this->agent->fresh()?->last_assigned_at)->toBeNull();
    Event::assertNotDispatched(TicketAssigned::class);
})->with([TicketStatus::Resolved, TicketStatus::Closed]);
