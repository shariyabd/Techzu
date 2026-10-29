<?php

declare(strict_types=1);

namespace App\Services\Sla;

use App\Enums\EscalationLevel;
use App\Enums\TicketPriority;
use App\Models\Ticket;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class SlaCalculator
{
    /** @var array<string, int> */
    private readonly array $resolutionMinutes;

    /** @param array<string,int> $resolutionMinutes */
    public function __construct(
        private readonly BusinessCalendar $calendar,
        array $resolutionMinutes,
        private readonly int $warningPercent,
    ) {
        foreach (TicketPriority::cases() as $priority) {
            $minutes = $resolutionMinutes[$priority->value] ?? null;

            if (! is_int($minutes) || $minutes <= 0) {
                throw new InvalidArgumentException("SLA resolution minutes for priority [{$priority->value}] must be a positive integer.");
            }
        }

        if ($warningPercent < 0 || $warningPercent > 100) {
            throw new InvalidArgumentException('SLA warning threshold must be between 0 and 100 percent.');
        }

        $this->resolutionMinutes = $resolutionMinutes;
    }

    public function deadlinesFor(TicketPriority $priority, CarbonImmutable $start): SlaDeadlines
    {
        $minutes = $this->resolutionMinutes[$priority->value];
        $warningMinutes = intdiv($minutes * $this->warningPercent, 100);

        return new SlaDeadlines(
            dueAt: $this->calendar->addBusinessMinutes($start, $minutes),
            warningAt: $this->calendar->addBusinessMinutes($start, $warningMinutes),
        );
    }

    public function applyInitial(Ticket $ticket, CarbonImmutable $start): void
    {
        $start = $start->utc()->startOfSecond();
        $deadlines = $this->deadlinesFor($ticket->priority, $start);

        $ticket->sla_started_at = $start;
        $ticket->sla_due_at = $deadlines->dueAt;
        $ticket->sla_warning_at = $deadlines->warningAt;
    }

    public function recalculateForPriority(Ticket $ticket, CarbonImmutable $now): void
    {
        $deadlines = $this->deadlinesFor($ticket->priority, $ticket->sla_started_at);

        $ticket->sla_due_at = $deadlines->dueAt;
        $ticket->sla_warning_at = $deadlines->warningAt;

        $this->lowerEscalation($ticket, $now);
    }

    public function pause(Ticket $ticket, CarbonImmutable $now): void
    {
        if ($ticket->isPaused()) {
            return;
        }

        $ticket->sla_paused_at = $now->utc()->startOfSecond();
    }

    public function resume(Ticket $ticket, CarbonImmutable $now): void
    {
        $pausedAt = $ticket->sla_paused_at;

        if ($pausedAt === null) {
            return;
        }

        $now = $now->utc()->startOfSecond();
        $remainingToDue = $this->calendar->businessMinutesBetween($pausedAt, $ticket->sla_due_at);
        $remainingToWarning = $this->calendar->businessMinutesBetween($pausedAt, $ticket->sla_warning_at);

        $ticket->sla_due_at = $this->calendar->addBusinessMinutes($now, $remainingToDue);
        $ticket->sla_warning_at = $this->calendar->addBusinessMinutes($now, $remainingToWarning);
        $ticket->sla_paused_at = null;

        $this->lowerEscalation($ticket, $now);
    }

    public function levelFor(Ticket $ticket, CarbonImmutable $now): EscalationLevel
    {
        $at = $ticket->sla_paused_at ?? $now;

        if ($at->greaterThanOrEqualTo($ticket->sla_due_at)) {
            return EscalationLevel::Breached;
        }

        if ($at->greaterThanOrEqualTo($ticket->sla_warning_at)) {
            return EscalationLevel::Approaching;
        }

        return EscalationLevel::None;
    }

    public function applyCurrentLevel(Ticket $ticket, CarbonImmutable $now): void
    {
        $ticket->escalation_level = $this->levelFor($ticket, $now);
    }

    private function lowerEscalation(Ticket $ticket, CarbonImmutable $now): void
    {
        $current = $ticket->getAttribute('escalation_level');
        $current = $current instanceof EscalationLevel ? $current : EscalationLevel::None;
        $target = $this->levelFor($ticket, $now);

        if ($target->value < $current->value) {
            $ticket->escalation_level = $target;
        }
    }
}
