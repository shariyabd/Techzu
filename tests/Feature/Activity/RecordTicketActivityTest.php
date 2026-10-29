<?php

declare(strict_types=1);

use App\Actions\Tickets\ChangeTicketPriority;
use App\Actions\Tickets\ChangeTicketStatus;
use App\Actions\Tickets\CreateTicket;
use App\Actions\Tickets\UpdateTicket;
use App\Enums\ActivityType;
use App\Enums\AssignmentMethod;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Events\TicketAssigned;
use App\Events\TicketCreated;
use App\Events\TicketReplied;
use App\Events\TicketSlaApproaching;
use App\Events\TicketSlaBreached;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-01 03:00:00', 'UTC'));
    $this->admin = User::factory()->admin()->create();
    $this->agent = User::factory()->agent()->create();
    $this->customer = User::factory()->customer()->create();
});

/** @return Collection<int, Activity> */
function activitiesFor(Ticket $ticket): Collection
{
    return Activity::query()
        ->where('log_name', 'ticket')
        ->where('subject_type', $ticket->getMorphClass())
        ->where('subject_id', $ticket->getKey())
        ->orderBy('id')
        ->get();
}

it('records created with the creator as causer', function (): void {
    $ticket = app(CreateTicket::class)->handle($this->customer, [
        'subject' => 'Hello',
        'description' => 'World',
        'priority' => TicketPriority::Low->value,
        'category_id' => Category::factory()->create()->id,
    ]);

    $activity = activitiesFor($ticket)->sole();
    expect($activity->event)->toBe(ActivityType::Created->value)
        ->and($activity->causer_id)->toBe($this->customer->id)
        ->and($activity->properties->get('number'))->toBe($ticket->number)
        ->and($activity->properties->get('priority'))->toBe('low')
        ->and($activity->description)->toBe(ActivityType::Created->describe($activity->properties->all()));
});

it('records imported tickets as imported with no causer', function (): void {
    $ticket = Ticket::factory()->create(['external_reference' => 'EXT-1']);

    TicketCreated::dispatch($ticket, null, true);

    $activity = activitiesFor($ticket)->sole();
    expect($activity->event)->toBe(ActivityType::Imported->value)
        ->and($activity->causer_id)->toBeNull()
        ->and($activity->properties->get('external_reference'))->toBe('EXT-1');
});

it('splits a category change from content updates', function (): void {
    $from = Category::factory()->create(['name' => 'Billing']);
    $to = Category::factory()->create(['name' => 'Technical']);
    $ticket = Ticket::factory()->create(['category_id' => $from->id, 'subject' => 'Old']);

    app(UpdateTicket::class)->handle($this->admin, $ticket, ['category_id' => $to->id, 'subject' => 'New', 'description' => 'Changed body']);

    $activities = activitiesFor($ticket);
    expect($activities->pluck('event')->all())->toBe([ActivityType::CategoryChanged->value, ActivityType::Updated->value]);

    $category = $activities->first();
    expect($category->properties->get('from'))->toBe(['id' => $from->id, 'name' => 'Billing'])
        ->and($category->properties->get('to'))->toBe(['id' => $to->id, 'name' => 'Technical']);

    $updated = $activities->last();
    expect($updated->properties->get('fields'))->toBe(['subject', 'description'])
        ->and($updated->properties->get('from'))->toBe(['subject' => 'Old'])
        ->and($updated->properties->get('to'))->toBe(['subject' => 'New']);
});

it('records sla_paused after a status change to waiting', function (): void {
    $ticket = Ticket::factory()->status(TicketStatus::InProgress)->create(['sla_paused_at' => null]);

    app(ChangeTicketStatus::class)->handle($this->admin, $ticket, TicketStatus::WaitingForCustomer);

    $activities = activitiesFor($ticket);
    expect($activities->pluck('event')->all())->toBe([ActivityType::StatusChanged->value, ActivityType::SlaPaused->value])
        ->and($activities->first()->properties->all())->toEqual(['from' => 'in_progress', 'to' => 'waiting_for_customer']);
});

it('records sla_resumed with the new deadline when leaving waiting', function (): void {
    $ticket = Ticket::factory()->status(TicketStatus::WaitingForCustomer)->create([
        'sla_paused_at' => now()->subHour(),
        'sla_due_at' => now()->addHours(2),
        'sla_warning_at' => now()->addHour(),
    ]);

    app(ChangeTicketStatus::class)->handle(null, $ticket, TicketStatus::InProgress);

    $activities = activitiesFor($ticket);
    expect($activities->pluck('event')->all())->toBe([ActivityType::StatusChanged->value, ActivityType::SlaResumed->value])
        ->and($activities->last()->properties->get('due_at'))->toBe($ticket->fresh()?->sla_due_at?->toIso8601String());
});

it('records a system actor as no causer even while a user is logged in', function (): void {
    $this->actingAs($this->customer);
    $ticket = Ticket::factory()->status(TicketStatus::WaitingForCustomer)->create(['sla_paused_at' => now()->subHour()]);

    app(ChangeTicketStatus::class)->handle(null, $ticket, TicketStatus::InProgress);

    expect(activitiesFor($ticket)->pluck('causer_id')->unique()->all())->toBe([null]);
});

it('records priority changes with from, to and the new deadline', function (): void {
    $ticket = Ticket::factory()->status(TicketStatus::InProgress)->priority(TicketPriority::Low)->create();

    app(ChangeTicketPriority::class)->handle($this->admin, $ticket, TicketPriority::Critical);

    $activity = activitiesFor($ticket)->sole();
    expect($activity->event)->toBe(ActivityType::PriorityChanged->value)
        ->and($activity->properties->get('from'))->toBe('low')
        ->and($activity->properties->get('to'))->toBe('critical')
        ->and($activity->causer_id)->toBe($this->admin->id);
});

it('records claimed, assigned and automatic assignments', function (AssignmentMethod $method, ActivityType $type, bool $systemActor): void {
    $previous = User::factory()->agent()->create(['name' => 'Previous Agent']);
    $ticket = Ticket::factory()->create();

    TicketAssigned::dispatch($ticket, $systemActor ? null : $this->admin, $this->agent, $method === AssignmentMethod::Manual ? $previous : null, $method);

    $activity = activitiesFor($ticket)->sole();
    expect($activity->event)->toBe($type->value)
        ->and($activity->properties->get('method'))->toBe($method->value)
        ->and($activity->properties->get('to'))->toBe(['id' => $this->agent->id, 'name' => $this->agent->name])
        ->and($activity->properties->get('from'))->toBe($method === AssignmentMethod::Manual ? ['id' => $previous->id, 'name' => 'Previous Agent'] : null)
        ->and($activity->causer_id)->toBe($systemActor ? null : $this->admin->id);
})->with([
    'claim' => [AssignmentMethod::Claimed, ActivityType::Claimed, false],
    'manual' => [AssignmentMethod::Manual, ActivityType::Assigned, false],
    'automatic' => [AssignmentMethod::Automatic, ActivityType::Assigned, true],
]);

it('records public replies and internal notes separately', function (): void {
    $ticket = Ticket::factory()->create(['customer_id' => $this->customer->id]);
    $public = TicketReply::factory()->create(['ticket_id' => $ticket->id, 'author_id' => $this->agent->id, 'is_internal' => false]);
    $internal = TicketReply::factory()->internal()->create(['ticket_id' => $ticket->id, 'author_id' => $this->agent->id]);

    TicketReplied::dispatch($ticket, $public, $this->agent);
    TicketReplied::dispatch($ticket, $internal, $this->agent);

    $customerReply = TicketReply::factory()->create(['ticket_id' => $ticket->id, 'author_id' => $this->customer->id, 'is_internal' => false]);
    TicketReplied::dispatch($ticket, $customerReply, $this->customer);

    $activities = activitiesFor($ticket);
    expect($activities->pluck('event')->all())->toBe([ActivityType::Replied->value, ActivityType::InternalNote->value, ActivityType::Replied->value])
        ->and($activities->get(1)->properties->get('reply_id'))->toBe($internal->id)
        ->and($activities->first()->properties->get('author_is_staff'))->toBeTrue()
        ->and($activities->last()->properties->get('author_is_staff'))->toBeFalse();
});

it('records sla_approaching and escalated as system entries', function (): void {
    $ticket = Ticket::factory()->create();

    TicketSlaApproaching::dispatch($ticket);
    TicketSlaBreached::dispatch($ticket);

    $activities = activitiesFor($ticket);
    expect($activities->pluck('event')->all())->toBe([ActivityType::SlaApproaching->value, ActivityType::Escalated->value])
        ->and($activities->pluck('causer_id')->unique()->all())->toBe([null])
        ->and($activities->last()->properties->get('level'))->toBe(2);
});
