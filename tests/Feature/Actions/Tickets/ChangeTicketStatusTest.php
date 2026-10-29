<?php

declare(strict_types=1);

use App\Actions\Tickets\ChangeTicketStatus;
use App\Enums\TicketStatus;
use App\Events\TicketResolved;
use App\Events\TicketStatusChanged;
use App\Exceptions\Domain\InvalidStatusTransition;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Sla\BusinessCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

const ALLOWED_TRANSITIONS = [
    ['open', 'in_progress'],
    ['open', 'waiting_for_customer'],
    ['open', 'resolved'],
    ['in_progress', 'waiting_for_customer'],
    ['in_progress', 'resolved'],
    ['waiting_for_customer', 'in_progress'],
    ['waiting_for_customer', 'resolved'],
    ['resolved', 'closed'],
    ['resolved', 'in_progress'],
];

dataset('allowed transitions', function (): array {
    $rows = [];
    foreach (ALLOWED_TRANSITIONS as [$from, $to]) {
        $rows["{$from} -> {$to}"] = [TicketStatus::from($from), TicketStatus::from($to)];
    }

    return $rows;
});

dataset('forbidden transitions', function (): array {
    $rows = [];
    foreach (TicketStatus::cases() as $from) {
        foreach (TicketStatus::cases() as $to) {
            if (! in_array([$from->value, $to->value], ALLOWED_TRANSITIONS, true)) {
                $rows["{$from->value} -> {$to->value}"] = [$from, $to];
            }
        }
    }

    return $rows;
});

function ticketInStatus(TicketStatus $status, CarbonImmutable $now): Ticket
{
    return Ticket::factory()->status($status)->create([
        'sla_started_at' => $now->subHours(2),
        'sla_warning_at' => $now->addHours(4),
        'sla_due_at' => $now->addHours(6),
        'sla_paused_at' => $status === TicketStatus::WaitingForCustomer ? $now->subHour() : null,
        'resolved_at' => in_array($status, [TicketStatus::Resolved, TicketStatus::Closed], true) ? $now->subMinutes(30) : null,
        'closed_at' => $status === TicketStatus::Closed ? $now->subMinutes(10) : null,
    ]);
}

beforeEach(function (): void {
    $this->now = CarbonImmutable::parse('2026-11-01 03:00:00', 'UTC');
    $this->travelTo($this->now);
    $this->admin = User::factory()->admin()->create();
});

it('applies every allowed transition', function (TicketStatus $from, TicketStatus $to): void {
    $ticket = ticketInStatus($from, $this->now);

    $result = app(ChangeTicketStatus::class)->handle($this->admin, $ticket, $to);

    expect($result->status)->toBe($to)
        ->and($ticket->fresh()?->status)->toBe($to);
})->with('allowed transitions');

it('rejects every forbidden transition and leaves the ticket untouched', function (TicketStatus $from, TicketStatus $to): void {
    $ticket = ticketInStatus($from, $this->now);
    $before = $ticket->fresh()?->getAttributes();

    expect(fn () => app(ChangeTicketStatus::class)->handle($this->admin, $ticket, $to))
        ->toThrow(InvalidStatusTransition::class);

    expect($ticket->fresh()?->getAttributes())->toBe($before);
})->with('forbidden transitions');

it('pauses the SLA when moving to waiting for customer', function (): void {
    $ticket = ticketInStatus(TicketStatus::InProgress, $this->now);
    $dueBefore = $ticket->sla_due_at;

    app(ChangeTicketStatus::class)->handle($this->admin, $ticket, TicketStatus::WaitingForCustomer);

    $fresh = $ticket->fresh();
    expect($fresh?->sla_paused_at?->equalTo($this->now))->toBeTrue()
        ->and($fresh?->sla_due_at?->equalTo($dueBefore))->toBeTrue()
        ->and($fresh?->isPaused())->toBeTrue();
});

it('resumes the SLA with the remaining business time when leaving waiting for customer', function (TicketStatus $to): void {
    $pausedAt = $this->now;
    $ticket = Ticket::factory()->status(TicketStatus::WaitingForCustomer)->create([
        'sla_started_at' => $pausedAt->subHours(2),
        'sla_warning_at' => $pausedAt->addHour(),
        'sla_due_at' => $pausedAt->addHours(3),
        'sla_paused_at' => $pausedAt,
    ]);

    $resumeAt = $pausedAt->addDay();
    $this->travelTo($resumeAt);

    app(ChangeTicketStatus::class)->handle($this->admin, $ticket, $to);

    $calendar = app(BusinessCalendar::class);
    $expectedDue = $calendar->addBusinessMinutes($resumeAt, $calendar->businessMinutesBetween($pausedAt, $pausedAt->addHours(3)));

    $fresh = $ticket->fresh();
    expect($fresh?->sla_paused_at)->toBeNull()
        ->and($fresh?->sla_due_at?->equalTo($expectedDue))->toBeTrue()
        ->and($fresh?->sla_due_at?->equalTo($resumeAt->addHours(3)))->toBeTrue();
})->with([
    'to in progress' => [TicketStatus::InProgress],
    'to resolved' => [TicketStatus::Resolved],
]);

it('stamps resolved_at and closed_at', function (): void {
    $ticket = ticketInStatus(TicketStatus::InProgress, $this->now);

    app(ChangeTicketStatus::class)->handle($this->admin, $ticket, TicketStatus::Resolved);
    expect($ticket->fresh()?->resolved_at?->equalTo($this->now))->toBeTrue();

    $this->travelTo($this->now->addHour());
    app(ChangeTicketStatus::class)->handle($this->admin, $ticket, TicketStatus::Closed);
    expect($ticket->fresh()?->closed_at?->equalTo($this->now->addHour()))->toBeTrue();
});

it('clears resolved_at on reopen and keeps the original deadline (TD-042)', function (): void {
    $ticket = ticketInStatus(TicketStatus::Resolved, $this->now);
    $dueBefore = $ticket->sla_due_at;

    app(ChangeTicketStatus::class)->handle($this->admin, $ticket, TicketStatus::InProgress);

    $fresh = $ticket->fresh();
    expect($fresh?->resolved_at)->toBeNull()
        ->and($fresh?->sla_due_at?->equalTo($dueBefore))->toBeTrue();
});

it('fires TicketStatusChanged, plus TicketResolved when resolving', function (): void {
    Event::fake([TicketStatusChanged::class, TicketResolved::class]);
    $ticket = ticketInStatus(TicketStatus::InProgress, $this->now);

    app(ChangeTicketStatus::class)->handle($this->admin, $ticket, TicketStatus::Resolved);

    Event::assertDispatched(TicketStatusChanged::class, fn (TicketStatusChanged $e): bool => $e->ticket->is($ticket)
        && $e->actor?->is($this->admin) === true
        && $e->from === TicketStatus::InProgress
        && $e->to === TicketStatus::Resolved);
    Event::assertDispatched(TicketResolved::class, 1);
});

it('does not fire TicketResolved for other transitions', function (): void {
    Event::fake([TicketStatusChanged::class, TicketResolved::class]);
    $ticket = ticketInStatus(TicketStatus::Open, $this->now);

    app(ChangeTicketStatus::class)->handle($this->admin, $ticket, TicketStatus::InProgress);

    Event::assertDispatched(TicketStatusChanged::class, 1);
    Event::assertNotDispatched(TicketResolved::class);
});

it('accepts a null actor for system transitions', function (): void {
    Event::fake([TicketStatusChanged::class]);
    $ticket = ticketInStatus(TicketStatus::WaitingForCustomer, $this->now);

    app(ChangeTicketStatus::class)->handle(null, $ticket, TicketStatus::InProgress);

    Event::assertDispatched(TicketStatusChanged::class, fn (TicketStatusChanged $e): bool => $e->actor === null);
});
