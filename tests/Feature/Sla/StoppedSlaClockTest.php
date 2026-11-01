<?php

declare(strict_types=1);

use App\Actions\Tickets\ChangeTicketPriority;
use App\Actions\Tickets\ChangeTicketStatus;
use App\Enums\EscalationLevel;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Events\TicketSlaBreached;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Sla\BusinessCalendar;
use App\Services\Sla\SlaCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

function stoppedClockAt(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, (string) config('business_hours.timezone'))->utc();
}

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->calendar = app(BusinessCalendar::class);
});

it('keeps the business time spent waiting for the customer when the priority changes, so it does not falsely breach', function (): void {
    $start = stoppedClockAt('2026-11-01 09:00:00');
    $deadlines = app(SlaCalculator::class)->deadlinesFor(TicketPriority::Medium, $start);
    $ticket = Ticket::factory()->status(TicketStatus::InProgress)->priority(TicketPriority::Medium)->create([
        'sla_started_at' => $start,
        'sla_due_at' => $deadlines->dueAt,
        'sla_warning_at' => $deadlines->warningAt,
    ]);

    $pausedAt = stoppedClockAt('2026-11-01 10:00:00');
    $this->travelTo($pausedAt);
    app(ChangeTicketStatus::class)->handle($this->admin, $ticket, TicketStatus::WaitingForCustomer);

    $resumedAt = $this->calendar->addBusinessMinutes($pausedAt, 480);
    $this->travelTo($resumedAt);
    app(ChangeTicketStatus::class)->handle($this->admin, $ticket, TicketStatus::InProgress);

    $changedAt = $this->calendar->addBusinessMinutes($resumedAt, 180);
    $this->travelTo($changedAt);
    app(ChangeTicketPriority::class)->handle($this->admin, $ticket, TicketPriority::High);

    $minutes = (int) config('sla.resolution_minutes.high');
    $fresh = $ticket->fresh();
    expect($fresh?->sla_paused_seconds)->toBe(480 * 60)
        ->and($fresh?->sla_due_at->equalTo($this->calendar->addBusinessMinutes($start, $minutes + 480)))->toBeTrue()
        ->and($fresh?->sla_due_at->greaterThan($changedAt))->toBeTrue();

    Event::fake([TicketSlaBreached::class]);
    $this->artisan('sla:scan')->assertSuccessful();
    Event::assertNotDispatched(TicketSlaBreached::class);
});

it('reopens with the business time that was left at resolution, so an old ticket does not breach on the next scan', function (): void {
    $resolvedAt = stoppedClockAt('2026-11-01 16:00:00');
    $ticket = Ticket::factory()->status(TicketStatus::InProgress)->priority(TicketPriority::High)->create([
        'sla_started_at' => stoppedClockAt('2026-11-01 09:00:00'),
        'sla_warning_at' => stoppedClockAt('2026-11-01 15:00:00'),
        'sla_due_at' => $this->calendar->addBusinessMinutes($resolvedAt, 60),
        'escalation_level' => EscalationLevel::Approaching,
    ]);

    $this->travelTo($resolvedAt);
    app(ChangeTicketStatus::class)->handle($this->admin, $ticket, TicketStatus::Resolved);

    $reopenedAt = $resolvedAt->addDays(3);
    $this->travelTo($reopenedAt);
    app(ChangeTicketStatus::class)->handle($this->admin, $ticket, TicketStatus::InProgress);

    $fresh = $ticket->fresh();
    expect($fresh?->sla_due_at->equalTo($this->calendar->addBusinessMinutes($reopenedAt, 60)))->toBeTrue()
        ->and($fresh?->sla_paused_seconds)->toBe($this->calendar->businessSecondsBetween($resolvedAt, $reopenedAt))
        ->and($fresh?->resolved_at)->toBeNull();

    Event::fake([TicketSlaBreached::class]);
    $this->artisan('sla:scan')->assertSuccessful();
    Event::assertNotDispatched(TicketSlaBreached::class);
});
