<?php

declare(strict_types=1);

use App\Enums\ActivityType;
use App\Enums\EscalationLevel;
use App\Enums\TicketStatus;
use App\Events\TicketSlaBreached;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\SlaApproachingNotification;
use App\Notifications\SlaBreachedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    config(['sla.redelivery_grace_seconds' => 120]);
    $this->now = CarbonImmutable::parse('2026-11-02 06:00:00', 'UTC');
    $this->travelTo($this->now);
    $this->admin = User::factory()->admin()->create();
    $this->escalationEntries = fn (Ticket $ticket): int => $ticket->activities()
        ->where('log_name', 'ticket')
        ->whereIn('event', [ActivityType::SlaApproaching->value, ActivityType::Escalated->value])
        ->count();
});

/** @param array<string, mixed> $attributes */
function escalatedTicket(array $attributes = []): Ticket
{
    $now = CarbonImmutable::now();

    return Ticket::factory()->status(TicketStatus::InProgress)->create([
        'sla_warning_at' => $now->subHours(2),
        'sla_due_at' => $now->subHour(),
        'escalation_level' => EscalationLevel::Breached,
        'escalation_notified_level' => EscalationLevel::None,
        'escalated_at' => $now->subMinutes(5),
        ...$attributes,
    ]);
}

it('redelivers an escalation whose dispatch was lost, exactly once, only after the grace period', function (EscalationLevel $level, string $notification, int $escalatedSecondsAgo, int $expected): void {
    $ticket = escalatedTicket([
        'sla_warning_at' => $this->now->subHour(),
        'sla_due_at' => $level === EscalationLevel::Breached ? $this->now->subMinutes(10) : $this->now->addHour(),
        'escalation_level' => $level,
        'escalated_at' => $this->now->subSeconds($escalatedSecondsAgo),
    ]);

    $this->artisan('sla:scan')->expectsOutputToContain("{$expected} redelivered")->assertSuccessful();
    $this->artisan('sla:scan')->expectsOutputToContain('0 redelivered')->assertSuccessful();

    Notification::assertSentToTimes($this->admin, $notification, $expected);
    expect(($this->escalationEntries)($ticket))->toBe($expected)
        ->and($ticket->fresh()?->escalation_notified_level)->toBe($expected === 1 ? $level : EscalationLevel::None);
})->with([
    'lost breach, past the grace' => [EscalationLevel::Breached, SlaBreachedNotification::class, 300, 1],
    'lost approaching warning, past the grace' => [EscalationLevel::Approaching, SlaApproachingNotification::class, 300, 1],
    'still inside the grace' => [EscalationLevel::Breached, SlaBreachedNotification::class, 60, 0],
]);

it('never redelivers for finished tickets', function (): void {
    $ticket = escalatedTicket(['status' => TicketStatus::Resolved, 'resolved_at' => $this->now->subMinute()]);

    $this->artisan('sla:scan')->assertSuccessful();

    Notification::assertNothingSent();
    expect(($this->escalationEntries)($ticket))->toBe(0);
});

it('sends one notification and records one activity entry when an escalation event is dispatched twice', function (): void {
    $ticket = escalatedTicket();

    TicketSlaBreached::dispatch($ticket);
    TicketSlaBreached::dispatch($ticket->fresh());

    Notification::assertSentToTimes($this->admin, SlaBreachedNotification::class, 1);
    expect(($this->escalationEntries)($ticket))->toBe(1);
});

it('notifies each escalation once on the normal scan path, even after the grace period', function (): void {
    $ticket = escalatedTicket(['escalation_level' => EscalationLevel::None, 'escalated_at' => null]);

    $this->artisan('sla:scan')->expectsOutputToContain('1 breached, 0 redelivered')->assertSuccessful();
    $this->travel(10)->minutes();
    $this->artisan('sla:scan')->expectsOutputToContain('0 breached, 0 redelivered')->assertSuccessful();

    Notification::assertSentToTimes($this->admin, SlaBreachedNotification::class, 1);
    expect(($this->escalationEntries)($ticket))->toBe(1)
        ->and($ticket->fresh()?->escalation_notified_level)->toBe(EscalationLevel::Breached);
});

it('notifies again when a ticket escalates again after its level was lowered', function (): void {
    $ticket = escalatedTicket(['escalation_notified_level' => EscalationLevel::Breached]);
    DB::table('tickets')->where('id', $ticket->id)->update([
        'escalation_level' => EscalationLevel::None->value,
        'sla_warning_at' => $this->now->addMinutes(20),
        'sla_due_at' => $this->now->addMinutes(30),
    ]);

    $this->travel(31)->minutes();
    $this->artisan('sla:scan')->expectsOutputToContain('1 breached')->assertSuccessful();

    Notification::assertSentToTimes($this->admin, SlaBreachedNotification::class, 1);
    expect($ticket->fresh()?->escalation_notified_level)->toBe(EscalationLevel::Breached);
});
