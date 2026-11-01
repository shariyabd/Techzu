<?php

declare(strict_types=1);

namespace App\Services\Sla;

use App\Models\Ticket;
use Carbon\CarbonImmutable;

final class SlaClock
{
    public function __construct(private readonly BusinessCalendar $calendar) {}

    /** @return array{remaining_seconds: int, running: bool, changes_at: string|null, resumes_at: string|null}|null */
    public function for(Ticket $ticket, CarbonImmutable $now): ?array
    {
        if (! $ticket->isActive()) {
            return null;
        }

        $pausedAt = $ticket->sla_paused_at;

        if ($pausedAt !== null) {
            return [
                'remaining_seconds' => $this->remainingSeconds($pausedAt, $ticket->sla_due_at),
                'running' => false,
                'changes_at' => null,
                'resumes_at' => null,
            ];
        }

        $running = $this->calendar->isBusinessTime($now);
        $changesAt = $this->calendar->nextTransition($now)?->toIso8601String();

        return [
            'remaining_seconds' => $this->remainingSeconds($now, $ticket->sla_due_at),
            'running' => $running,
            'changes_at' => $changesAt,
            'resumes_at' => $running ? null : $changesAt,
        ];
    }

    private function remainingSeconds(CarbonImmutable $from, CarbonImmutable $dueAt): int
    {
        if ($from->lessThan($dueAt)) {
            return $this->calendar->businessSecondsBetween($from, $dueAt);
        }

        return -$this->calendar->businessSecondsBetween($dueAt, $from);
    }
}
