<?php

declare(strict_types=1);

use App\Actions\Conversation\AddReply;
use App\Enums\ActivityType;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketRepliedNotification;
use App\Services\Sla\BusinessCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->resolvedAt = CarbonImmutable::parse('2026-11-01 16:00:00', (string) config('business_hours.timezone'))->utc();
    $this->customer = User::factory()->customer()->create();
    $this->agent = User::factory()->agent()->create();
    $this->ticket = Ticket::factory()->status(TicketStatus::Resolved)->assignedTo($this->agent)->create([
        'customer_id' => $this->customer->id,
        'created_by_id' => $this->customer->id,
        'sla_started_at' => $this->resolvedAt->subHours(7),
        'sla_warning_at' => $this->resolvedAt->subHour(),
        'sla_due_at' => app(BusinessCalendar::class)->addBusinessMinutes($this->resolvedAt, 60),
        'resolved_at' => $this->resolvedAt,
    ]);
    $this->travelTo($this->resolvedAt->addDays(3));
});

it('reopens a resolved ticket on a public customer reply, resumes the remaining SLA and tells the assigned agent', function (): void {
    Notification::fake();

    app(AddReply::class)->handle($this->customer, $this->ticket, 'It broke again.', false);

    $fresh = $this->ticket->fresh();
    expect($fresh?->status)->toBe(TicketStatus::InProgress)
        ->and($fresh?->resolved_at)->toBeNull()
        ->and($fresh?->sla_due_at->equalTo(app(BusinessCalendar::class)->addBusinessMinutes(CarbonImmutable::now(), 60)))->toBeTrue()
        ->and($fresh?->activities()->where('event', ActivityType::StatusChanged->value)->where('properties->from', TicketStatus::Resolved->value)->exists())->toBeTrue();
    Notification::assertSentTo($this->agent, TicketRepliedNotification::class);
});

it('keeps a resolved ticket resolved when staff reply or add an internal note', function (string $who, bool $internal): void {
    $author = $who === 'admin' ? User::factory()->admin()->create() : $this->agent;

    app(AddReply::class)->handle($author, $this->ticket, 'Following up.', $internal);

    $fresh = $this->ticket->fresh();
    expect($fresh?->status)->toBe(TicketStatus::Resolved)
        ->and($fresh?->resolved_at?->equalTo($this->resolvedAt))->toBeTrue();
})->with([
    'agent public reply' => ['agent', false],
    'agent internal note' => ['agent', true],
    'admin public reply' => ['admin', false],
]);
