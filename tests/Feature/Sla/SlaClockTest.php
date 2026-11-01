<?php

declare(strict_types=1);

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Services\Sla\SlaCalculator;
use App\Services\Sla\SlaClock;
use Carbon\CarbonImmutable;

function slaClockAt(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, (string) config('business_hours.timezone'))->utc();
}

function slaClockIso(?string $local): ?string
{
    return $local === null ? null : slaClockAt($local)->toIso8601String();
}

it('counts the SLA down in business seconds only', function (TicketStatus $status, ?string $pausedAt, string $viewedAt, ?array $expected): void {
    $start = slaClockAt('2026-10-29 17:00:00');
    $deadlines = app(SlaCalculator::class)->deadlinesFor(TicketPriority::Critical, $start);
    $ticket = Ticket::factory()->status($status)->priority(TicketPriority::Critical)->make([
        'sla_started_at' => $start,
        'sla_due_at' => $deadlines->dueAt,
        'sla_warning_at' => $deadlines->warningAt,
        'sla_paused_at' => $pausedAt === null ? null : slaClockAt($pausedAt),
    ]);

    $clock = app(SlaClock::class)->for($ticket, slaClockAt($viewedAt));

    expect($clock)->toBe($expected === null ? null : [
        'remaining_seconds' => $expected[0],
        'running' => $expected[1],
        'changes_at' => slaClockIso($expected[2]),
        'resumes_at' => slaClockIso($expected[3]),
    ]);
})->with([
    'running inside business hours until closing' => [TicketStatus::InProgress, null, '2026-10-29 17:30:00', [5400, true, '2026-10-29 18:00:00', null]],
    'stopped over the weekend until the next opening' => [TicketStatus::InProgress, null, '2026-10-30 12:00:00', [3600, false, '2026-11-01 09:00:00', '2026-11-01 09:00:00']],
    'overdue counts the business time since due as negative' => [TicketStatus::InProgress, null, '2026-11-01 10:40:00', [-2400, true, '2026-11-01 18:00:00', null]],
    'overdue outside business hours stays frozen at the overdue amount' => [TicketStatus::Open, null, '2026-11-01 20:00:00', [-28800, false, '2026-11-02 09:00:00', '2026-11-02 09:00:00']],
    'paused while waiting for the customer is frozen at the pause' => [TicketStatus::WaitingForCustomer, '2026-10-29 17:30:00', '2026-11-03 12:00:00', [5400, false, null, null]],
    'resolved tickets have no clock' => [TicketStatus::Resolved, null, '2026-10-29 17:30:00', null],
    'closed tickets have no clock' => [TicketStatus::Closed, null, '2026-10-29 17:30:00', null],
]);
