<?php

declare(strict_types=1);

use App\Actions\Assignment\AssignTicketAutomatically;
use App\Enums\ActivityType;
use App\Enums\AssignmentMethod;
use App\Enums\TicketStatus;
use App\Events\TicketAssigned;
use App\Jobs\AutoAssignTicket;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketNeedsAssignmentNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    config()->set('tickets.auto_assign', true);
    $this->now = CarbonImmutable::parse('2026-11-01 03:00:00', 'UTC');
    $this->travelTo($this->now);
    $this->category = Category::factory()->create();
});

function runAutoAssign(int $ticketId): void
{
    app()->call([new AutoAssignTicket($ticketId), 'handle']);
}

it('assigns the least-loaded available agent of the category', function (): void {
    $busy = User::factory()->agent([$this->category->id])->create();
    Ticket::factory()->count(2)->status(TicketStatus::InProgress)->assignedTo($busy)->create();
    $free = User::factory()->agent([$this->category->id])->create(['last_assigned_at' => null]);
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['category_id' => $this->category->id, 'assigned_agent_id' => null]);

    runAutoAssign($ticket->id);

    $fresh = $ticket->fresh();
    expect($fresh?->assigned_agent_id)->toBe($free->id)
        ->and($fresh?->status)->toBe(TicketStatus::InProgress)
        ->and($free->fresh()?->last_assigned_at?->equalTo($this->now))->toBeTrue();
    $activity = Activity::query()
        ->where('subject_id', $ticket->id)
        ->where('event', ActivityType::Assigned->value)
        ->sole();
    expect($activity->causer_id)->toBeNull()
        ->and($activity->properties->get('method'))->toBe(AssignmentMethod::Automatic->value);
});

it('leaves the ticket unassigned and logs when no agent is eligible', function (): void {
    Log::spy();
    User::factory()->agent([Category::factory()->create()->id])->create();
    User::factory()->agent([$this->category->id])->unavailable()->create();
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['category_id' => $this->category->id, 'assigned_agent_id' => null]);

    runAutoAssign($ticket->id);

    expect($ticket->fresh()?->assigned_agent_id)->toBeNull()
        ->and($ticket->fresh()?->status)->toBe(TicketStatus::Open);
    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'no eligible agent')
            && ($context['ticket'] ?? null) === $ticket->number)
        ->once();
});

it('exits quietly when the ticket was claimed between selection and update', function (): void {
    Event::fake([TicketAssigned::class]);
    User::factory()->agent([$this->category->id])->create();
    $claimer = User::factory()->agent()->create();
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['category_id' => $this->category->id, 'assigned_agent_id' => null]);
    $stale = Ticket::query()->findOrFail($ticket->id);
    Ticket::query()->whereKey($ticket->id)->update(['assigned_agent_id' => $claimer->id]);

    expect(app(AssignTicketAutomatically::class)->handle($stale))->toBeNull()
        ->and($ticket->fresh()?->assigned_agent_id)->toBe($claimer->id);
    Event::assertNotDispatched(TicketAssigned::class);
});

it('skips tickets that are already assigned, finished or deleted', function (): void {
    Event::fake([TicketAssigned::class]);
    User::factory()->agent([$this->category->id])->create();
    $owner = User::factory()->agent()->create();
    $assigned = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($owner)->create(['category_id' => $this->category->id]);
    $resolved = Ticket::factory()->status(TicketStatus::Resolved)->create(['category_id' => $this->category->id, 'assigned_agent_id' => null]);

    runAutoAssign($assigned->id);
    runAutoAssign($resolved->id);
    runAutoAssign(999_999);

    expect($assigned->fresh()?->assigned_agent_id)->toBe($owner->id)
        ->and($resolved->fresh()?->assigned_agent_id)->toBeNull();
    Event::assertNotDispatched(TicketAssigned::class);
});

it('exits quietly when the ticket was finished before the assignment ran', function (TicketStatus $finished): void {
    Event::fake([TicketAssigned::class]);
    User::factory()->agent([$this->category->id])->create();
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['category_id' => $this->category->id, 'assigned_agent_id' => null]);
    $stale = Ticket::query()->findOrFail($ticket->id);
    Ticket::query()->whereKey($ticket->id)->update(['status' => $finished->value]);

    expect(app(AssignTicketAutomatically::class)->handle($stale))->toBeNull()
        ->and($ticket->fresh()?->assigned_agent_id)->toBeNull()
        ->and($ticket->fresh()?->status)->toBe($finished);
    Event::assertNotDispatched(TicketAssigned::class);
})->with([TicketStatus::Resolved, TicketStatus::Closed]);

it('tells the admins once, and never the customer, when no agent can take the ticket', function (): void {
    Notification::fake();
    $admins = User::factory()->admin()->count(2)->create();
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['category_id' => $this->category->id, 'assigned_agent_id' => null]);

    runAutoAssign($ticket->id);

    Notification::assertSentTo($admins, TicketNeedsAssignmentNotification::class, 1);
    Notification::assertNotSentTo($ticket->customer, TicketNeedsAssignmentNotification::class);
});
