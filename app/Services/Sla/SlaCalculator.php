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
    private const int SECONDS_PER_MINUTE = 60;

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

    public function deadlinesFor(TicketPriority $priority, CarbonImmutable $start, int $stoppedSeconds = 0): SlaDeadlines
    {
        $minutes = $this->resolutionMinutes[$priority->value];
        $warningMinutes = intdiv($minutes * $this->warningPercent, 100);
        $stoppedSeconds = max(0, $stoppedSeconds);

        return new SlaDeadlines(
            dueAt: $this->calendar->addBusinessSeconds($start, $minutes * self::SECONDS_PER_MINUTE + $stoppedSeconds),
            warningAt: $this->calendar->addBusinessSeconds($start, $warningMinutes * self::SECONDS_PER_MINUTE + $stoppedSeconds),
        );
    }

    public function applyInitial(Ticket $ticket, CarbonImmutable $start): void
    {
        $start = $start->utc()->startOfSecond();
        $deadlines = $this->deadlinesFor($ticket->priority, $start);

        $ticket->sla_started_at = $start;
        $ticket->sla_due_at = $deadlines->dueAt;
        $ticket->sla_warning_at = $deadlines->warningAt;
        $ticket->sla_paused_seconds = 0;
    }

    public function recalculateForPriority(Ticket $ticket, CarbonImmutable $now): void
    {
        $deadlines = $this->deadlinesFor($ticket->priority, $ticket->sla_started_at, $this->pausedSeconds($ticket));

        $ticket->sla_due_at = $deadlines->dueAt;
        $ticket->sla_warning_at = $deadlines->warningAt;

        $this->lowerEscalation($ticket, $this->clockStoppedAt($ticket) ?? $now);
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

        $ticket->sla_paused_at = null;
        $this->restartClock($ticket, $pausedAt, $now);
    }

    public function reopen(Ticket $ticket, CarbonImmutable $now): void
    {
        $resolvedAt = $ticket->resolved_at;

        if ($resolvedAt === null) {
            return;
        }

        $this->restartClock($ticket, $resolvedAt, $now);
    }

    public function levelFor(Ticket $ticket, CarbonImmutable $now): EscalationLevel
    {
        return $this->levelAt($ticket, $this->clockStoppedAt($ticket) ?? $now);
    }

    public function applyCurrentLevelAsNotified(Ticket $ticket, CarbonImmutable $now): void
    {
        $ticket->escalation_level = $this->levelFor($ticket, $now);
        $ticket->escalation_notified_level = $ticket->escalation_level;
    }

    private function restartClock(Ticket $ticket, CarbonImmutable $stoppedAt, CarbonImmutable $now): void
    {
        $now = $now->utc()->startOfSecond();
        $remainingToDue = $this->calendar->businessSecondsBetween($stoppedAt, $ticket->sla_due_at);
        $remainingToWarning = $this->calendar->businessSecondsBetween($stoppedAt, $ticket->sla_warning_at);

        $ticket->sla_due_at = $this->calendar->addBusinessSeconds($now, $remainingToDue);
        $ticket->sla_warning_at = $this->calendar->addBusinessSeconds($now, $remainingToWarning);
        $ticket->sla_paused_seconds = $this->pausedSeconds($ticket) + $this->calendar->businessSecondsBetween($stoppedAt, $now);

        $this->lowerEscalation($ticket, $now);
    }

    private function clockStoppedAt(Ticket $ticket): ?CarbonImmutable
    {
        if ($ticket->sla_paused_at !== null) {
            return $ticket->sla_paused_at;
        }

        return $ticket->isActive() ? null : $ticket->resolved_at;
    }

    private function pausedSeconds(Ticket $ticket): int
    {
        return (int) $ticket->getAttribute('sla_paused_seconds');
    }

    private function levelAt(Ticket $ticket, CarbonImmutable $at): EscalationLevel
    {
        if ($at->greaterThanOrEqualTo($ticket->sla_due_at)) {
            return EscalationLevel::Breached;
        }

        if ($at->greaterThanOrEqualTo($ticket->sla_warning_at)) {
            return EscalationLevel::Approaching;
        }

        return EscalationLevel::None;
    }

    private function lowerEscalation(Ticket $ticket, CarbonImmutable $at): void
    {
        $current = $ticket->getAttribute('escalation_level');
        $current = $current instanceof EscalationLevel ? $current : EscalationLevel::None;
        $target = $this->levelAt($ticket, $at);

        if ($target->value < $current->value) {
            $ticket->escalation_level = $target;
        }
    }
}
