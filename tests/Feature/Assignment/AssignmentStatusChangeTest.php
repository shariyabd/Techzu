<?php

declare(strict_types=1);

use App\Actions\Assignment\AssignTicket;
use App\Actions\Assignment\AssignTicketAutomatically;
use App\Actions\Assignment\ClaimTicket;
use App\Enums\ActivityType;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    $this->category = Category::factory()->create();
    $this->admin = User::factory()->admin()->create();
    $this->agent = User::factory()->agent([$this->category->id])->create(['last_assigned_at' => null]);
});

it('moves an open ticket to in progress through one status change logged after the assignment', function (string $path, ActivityType $assignmentType, ?string $causer): void {
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['assigned_agent_id' => null, 'category_id' => $this->category->id]);

    match ($path) {
        'claim' => app(ClaimTicket::class)->handle($this->agent, $ticket),
        'manual' => app(AssignTicket::class)->handle($this->admin, $ticket, $this->agent),
        'automatic' => app(AssignTicketAutomatically::class)->handle($ticket),
    };

    $activities = Activity::query()
        ->where('subject_type', $ticket->getMorphClass())
        ->where('subject_id', $ticket->id)
        ->whereIn('event', [$assignmentType->value, ActivityType::StatusChanged->value])
        ->orderBy('id')
        ->get();
    $statusChange = $activities->last();

    expect($ticket->fresh()?->status)->toBe(TicketStatus::InProgress)
        ->and($activities->pluck('event')->all())->toBe([$assignmentType->value, ActivityType::StatusChanged->value])
        ->and($statusChange?->properties->all())->toEqual(['from' => TicketStatus::Open->value, 'to' => TicketStatus::InProgress->value])
        ->and($statusChange?->causer_id)->toBe($causer === null ? null : $this->{$causer}->id);
})->with([
    'claim' => ['claim', ActivityType::Claimed, 'agent'],
    'manual assignment' => ['manual', ActivityType::Assigned, 'admin'],
    'automatic assignment' => ['automatic', ActivityType::Assigned, null],
]);
