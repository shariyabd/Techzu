<?php

declare(strict_types=1);

use App\Actions\Tickets\ChangeTicketPriority;
use App\Enums\EscalationLevel;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Events\TicketPriorityChanged;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Sla\SlaCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->started = CarbonImmutable::parse('2026-11-01 03:00:00', 'UTC');
    $this->now = $this->started->addHours(3);
    $this->travelTo($this->now);
    $this->admin = User::factory()->admin()->create();
});

function ticketWithPriority(TicketPriority $priority, CarbonImmutable $started, array $extra = []): Ticket
{
    $deadlines = app(SlaCalculator::class)->deadlinesFor($priority, $started);

    return Ticket::factory()->status(TicketStatus::InProgress)->priority($priority)->create(array_merge([
        'sla_started_at' => $started,
        'sla_due_at' => $deadlines->dueAt,
        'sla_warning_at' => $deadlines->warningAt,
        'sla_paused_at' => null,
        'escalation_level' => EscalationLevel::None,
    ], $extra));
}

it('recalculates the deadlines from sla_started_at (TD-043)', function (): void {
    $ticket = ticketWithPriority(TicketPriority::Low, $this->started);

    app(ChangeTicketPriority::class)->handle($this->admin, $ticket, TicketPriority::High);

    $expected = app(SlaCalculator::class)->deadlinesFor(TicketPriority::High, $this->started);
    $fresh = $ticket->fresh();

    expect($fresh?->priority)->toBe(TicketPriority::High)
        ->and($fresh?->sla_due_at?->equalTo($expected->dueAt))->toBeTrue()
        ->and($fresh?->sla_warning_at?->equalTo($expected->warningAt))->toBeTrue()
        ->and($fresh?->sla_started_at?->equalTo($this->started))->toBeTrue();
});

it('can make a ticket breached immediately when raised to critical', function (): void {
    $ticket = ticketWithPriority(TicketPriority::Low, $this->started);

    app(ChangeTicketPriority::class)->handle($this->admin, $ticket, TicketPriority::Critical);

    expect($ticket->fresh()?->sla_due_at?->lessThanOrEqualTo($this->now))->toBeTrue();
});

it('lowers the escalation level when the new deadline is later', function (): void {
    $ticket = ticketWithPriority(TicketPriority::Critical, $this->started, [
        'escalation_level' => EscalationLevel::Breached,
        'escalated_at' => $this->now->subHour(),
    ]);

    app(ChangeTicketPriority::class)->handle($this->admin, $ticket, TicketPriority::Low);

    expect($ticket->fresh()?->escalation_level)->toBe(EscalationLevel::None);
});

it('keeps the pause of a waiting ticket', function (): void {
    $pausedAt = $this->now->subHour();
    $ticket = ticketWithPriority(TicketPriority::Low, $this->started, [
        'status' => TicketStatus::WaitingForCustomer,
        'sla_paused_at' => $pausedAt,
    ]);

    app(ChangeTicketPriority::class)->handle($this->admin, $ticket, TicketPriority::Medium);

    expect($ticket->fresh()?->sla_paused_at?->equalTo($pausedAt))->toBeTrue();
});

it('fires TicketPriorityChanged with from and to', function (): void {
    Event::fake([TicketPriorityChanged::class]);
    $ticket = ticketWithPriority(TicketPriority::Medium, $this->started);

    app(ChangeTicketPriority::class)->handle($this->admin, $ticket, TicketPriority::High);

    Event::assertDispatched(TicketPriorityChanged::class, fn (TicketPriorityChanged $e): bool => $e->ticket->is($ticket)
        && $e->actor->is($this->admin)
        && $e->from === TicketPriority::Medium
        && $e->to === TicketPriority::High);
});

it('is a no-op for the same priority', function (): void {
    Event::fake([TicketPriorityChanged::class]);
    $ticket = ticketWithPriority(TicketPriority::Medium, $this->started);
    $before = $ticket->fresh()?->getAttributes();

    app(ChangeTicketPriority::class)->handle($this->admin, $ticket, TicketPriority::Medium);

    Event::assertNotDispatched(TicketPriorityChanged::class);
    expect($ticket->fresh()?->getAttributes())->toBe($before);
});
