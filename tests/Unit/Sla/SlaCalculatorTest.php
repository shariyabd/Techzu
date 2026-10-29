<?php

declare(strict_types=1);

use App\Enums\EscalationLevel;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Services\Sla\BusinessCalendar;
use App\Services\Sla\SlaCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    Event::listen(QueryExecuted::class, static function (QueryExecuted $query): never {
        throw new RuntimeException('SlaCalculator unit tests must not touch the database; ran: '.$query->sql);
    });
});

/**
 * @param  list<string>  $holidays
 * @param  array<string, int>|null  $minutes
 */
function slaCalculator(array $holidays = [], ?array $minutes = null, int $warningPercent = 75): SlaCalculator
{
    $day = [['09:00', '18:00']];
    $week = [
        'sunday' => $day, 'monday' => $day, 'tuesday' => $day, 'wednesday' => $day, 'thursday' => $day,
        'friday' => [], 'saturday' => [],
    ];

    return new SlaCalculator(
        new BusinessCalendar($week, 'Asia/Dhaka', $holidays),
        $minutes ?? ['low' => 2880, 'medium' => 1440, 'high' => 480, 'critical' => 120],
        $warningPercent,
    );
}

function slaAt(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Asia/Dhaka')->utc();
}

function slaLocal(?CarbonImmutable $instant): ?string
{
    return $instant?->setTimezone('Asia/Dhaka')->format('Y-m-d H:i:s');
}

/** @param array<string, mixed> $attributes */
function slaMake(array $attributes = []): Ticket
{
    return Ticket::factory()->make(array_merge([
        'category_id' => 1,
        'customer_id' => 1,
        'created_by_id' => 1,
        'assigned_agent_id' => null,
        'status' => TicketStatus::InProgress,
        'priority' => TicketPriority::Critical,
        'sla_paused_at' => null,
        'escalation_level' => EscalationLevel::None,
    ], $attributes));
}

/** @param array<string, mixed> $attributes */
function slaTicket(SlaCalculator $calculator, TicketPriority $priority, string $startLocal, array $attributes = []): Ticket
{
    $ticket = slaMake(array_merge(['priority' => $priority], $attributes));
    $calculator->applyInitial($ticket, slaAt($startLocal));

    return $ticket;
}

describe('construction', function (): void {
    it('rejects incomplete or invalid SLA rules', function (array $minutes, int $percent): void {
        expect(fn () => slaCalculator([], $minutes, $percent))->toThrow(InvalidArgumentException::class);
    })->with([
        'missing priority' => [['low' => 2880, 'medium' => 1440, 'high' => 480], 75],
        'zero minutes' => [['low' => 2880, 'medium' => 1440, 'high' => 480, 'critical' => 0], 75],
        'percent above 100' => [['low' => 2880, 'medium' => 1440, 'high' => 480, 'critical' => 120], 101],
        'negative percent' => [['low' => 2880, 'medium' => 1440, 'high' => 480, 'critical' => 120], -1],
    ]);
});

describe('deadlinesFor', function (): void {
    it('computes the due date and the 75% warning in business time', function (TicketPriority $priority, string $start, string $due, string $warning): void {
        $deadlines = slaCalculator()->deadlinesFor($priority, slaAt($start));

        expect(slaLocal($deadlines->dueAt))->toBe($due)
            ->and(slaLocal($deadlines->warningAt))->toBe($warning);
    })->with([
        'PRD example: Critical Thu 17:00' => [TicketPriority::Critical, '2026-10-29 17:00:00', '2026-11-01 10:00:00', '2026-11-01 09:30:00'],
        'High Thu 17:00' => [TicketPriority::High, '2026-10-29 17:00:00', '2026-11-01 16:00:00', '2026-11-01 14:00:00'],
        'Medium Sun 09:00, warning on a closing boundary' => [TicketPriority::Medium, '2026-11-01 09:00:00', '2026-11-03 15:00:00', '2026-11-02 18:00:00'],
        'Low Thu 17:00 spans more than a week' => [TicketPriority::Low, '2026-10-29 17:00:00', '2026-11-08 11:00:00', '2026-11-04 17:00:00'],
    ]);

    it('floors the warning minutes', function (): void {
        $calculator = slaCalculator([], ['low' => 2880, 'medium' => 1440, 'high' => 480, 'critical' => 10], 75);

        $deadlines = $calculator->deadlinesFor(TicketPriority::Critical, slaAt('2026-11-01 09:00:00'));

        expect(slaLocal($deadlines->warningAt))->toBe('2026-11-01 09:07:00')
            ->and(slaLocal($deadlines->dueAt))->toBe('2026-11-01 09:10:00');
    });
});

describe('applyInitial', function (): void {
    it('sets the UTC start, due and warning without saving', function (): void {
        $ticket = slaMake(['priority' => TicketPriority::Critical]);

        slaCalculator()->applyInitial($ticket, CarbonImmutable::parse('2026-10-29 17:00:00', 'Asia/Dhaka'));

        expect($ticket->exists)->toBeFalse()
            ->and($ticket->sla_started_at->format('Y-m-d H:i:s'))->toBe('2026-10-29 11:00:00')
            ->and(slaLocal($ticket->sla_due_at))->toBe('2026-11-01 10:00:00')
            ->and(slaLocal($ticket->sla_warning_at))->toBe('2026-11-01 09:30:00')
            ->and($ticket->sla_paused_at)->toBeNull()
            ->and($ticket->escalation_level)->toBe(EscalationLevel::None);
    });
});

describe('pause and resume', function (): void {
    it('shifts due and warning by the business time left at the pause', function (string $pausedAt, string $resumedAt, string $due, string $warning): void {
        $calculator = slaCalculator();
        $ticket = slaTicket($calculator, TicketPriority::Critical, '2026-11-01 09:00:00');

        $calculator->pause($ticket, slaAt($pausedAt));
        expect(slaLocal($ticket->sla_paused_at))->toBe($pausedAt);

        $calculator->resume($ticket, slaAt($resumedAt));

        expect($ticket->sla_paused_at)->toBeNull()
            ->and(slaLocal($ticket->sla_due_at))->toBe($due)
            ->and(slaLocal($ticket->sla_warning_at))->toBe($warning)
            ->and(slaLocal($ticket->sla_started_at))->toBe('2026-11-01 09:00:00')
            ->and($ticket->exists)->toBeFalse();
    })->with([
        'paused 10:00, resumed next day 15:00' => ['2026-11-01 10:00:00', '2026-11-02 15:00:00', '2026-11-02 16:00:00', '2026-11-02 15:30:00'],
        'resumed late Thursday, carries over the weekend' => ['2026-11-01 10:00:00', '2026-11-05 17:30:00', '2026-11-08 09:30:00', '2026-11-05 18:00:00'],
        'resumed on a Friday, counts from the next opening' => ['2026-11-01 10:00:00', '2026-11-06 12:00:00', '2026-11-08 10:00:00', '2026-11-08 09:30:00'],
        'paused and resumed at the same instant' => ['2026-11-01 10:00:00', '2026-11-01 10:00:00', '2026-11-01 11:00:00', '2026-11-01 10:30:00'],
        'paused between warning and due: warning floored to zero' => ['2026-11-01 10:45:00', '2026-11-02 10:00:00', '2026-11-02 10:15:00', '2026-11-02 10:00:00'],
    ]);

    it('keeps the original pause moment when paused twice', function (): void {
        $calculator = slaCalculator();
        $ticket = slaTicket($calculator, TicketPriority::Critical, '2026-11-01 09:00:00');

        $calculator->pause($ticket, slaAt('2026-11-01 10:00:00'));
        $calculator->pause($ticket, slaAt('2026-11-01 10:20:00'));

        expect(slaLocal($ticket->sla_paused_at))->toBe('2026-11-01 10:00:00');
    });

    it('does nothing when resuming an unpaused ticket', function (): void {
        $calculator = slaCalculator();
        $ticket = slaTicket($calculator, TicketPriority::Critical, '2026-11-01 09:00:00');

        $calculator->resume($ticket, slaAt('2026-11-02 15:00:00'));

        expect(slaLocal($ticket->sla_due_at))->toBe('2026-11-01 11:00:00')
            ->and(slaLocal($ticket->sla_warning_at))->toBe('2026-11-01 10:30:00');
    });

    it('resumes a ticket paused after its deadline as still breached (remaining 0)', function (): void {
        $calculator = slaCalculator();
        $ticket = slaTicket($calculator, TicketPriority::Critical, '2026-11-01 09:00:00', [
            'escalation_level' => EscalationLevel::Breached,
        ]);

        $calculator->pause($ticket, slaAt('2026-11-01 12:00:00'));
        $calculator->resume($ticket, slaAt('2026-11-02 10:00:00'));

        expect(slaLocal($ticket->sla_due_at))->toBe('2026-11-02 10:00:00')
            ->and(slaLocal($ticket->sla_warning_at))->toBe('2026-11-02 10:00:00')
            ->and($ticket->escalation_level)->toBe(EscalationLevel::Breached)
            ->and($calculator->levelFor($ticket, slaAt('2026-11-02 10:00:00')))->toBe(EscalationLevel::Breached);
    });

    it('lowers a stale escalation level when the resume moves the deadline later', function (): void {
        $calculator = slaCalculator();
        $ticket = slaTicket($calculator, TicketPriority::Critical, '2026-11-01 09:00:00', [
            'escalation_level' => EscalationLevel::Breached,
        ]);

        $calculator->pause($ticket, slaAt('2026-11-01 10:00:00'));
        $calculator->resume($ticket, slaAt('2026-11-02 09:00:00'));

        expect(slaLocal($ticket->sla_due_at))->toBe('2026-11-02 10:00:00')
            ->and($ticket->escalation_level)->toBe(EscalationLevel::None);
    });
});

describe('recalculateForPriority', function (): void {
    it('measures the new deadline from sla_started_at, not from now', function (): void {
        $calculator = slaCalculator();
        $ticket = slaTicket($calculator, TicketPriority::Medium, '2026-11-01 09:00:00');

        $ticket->priority = TicketPriority::High;
        $calculator->recalculateForPriority($ticket, slaAt('2026-11-02 10:00:00'));

        expect(slaLocal($ticket->sla_started_at))->toBe('2026-11-01 09:00:00')
            ->and(slaLocal($ticket->sla_due_at))->toBe('2026-11-01 17:00:00')
            ->and(slaLocal($ticket->sla_warning_at))->toBe('2026-11-01 15:00:00')
            ->and($ticket->exists)->toBeFalse();
    });

    it('recalculates from the start while paused and keeps the pause', function (): void {
        $calculator = slaCalculator();
        $ticket = slaTicket($calculator, TicketPriority::Low, '2026-11-01 09:00:00');
        $calculator->pause($ticket, slaAt('2026-11-01 10:00:00'));

        $ticket->priority = TicketPriority::Critical;
        $calculator->recalculateForPriority($ticket, slaAt('2026-11-02 09:00:00'));

        expect(slaLocal($ticket->sla_due_at))->toBe('2026-11-01 11:00:00')
            ->and(slaLocal($ticket->sla_warning_at))->toBe('2026-11-01 10:30:00')
            ->and(slaLocal($ticket->sla_paused_at))->toBe('2026-11-01 10:00:00')
            ->and($ticket->escalation_level)->toBe(EscalationLevel::None);

        $calculator->resume($ticket, slaAt('2026-11-02 12:00:00'));

        expect(slaLocal($ticket->sla_due_at))->toBe('2026-11-02 13:00:00')
            ->and(slaLocal($ticket->sla_warning_at))->toBe('2026-11-02 12:30:00');
    });

    it('lowers escalation_level when the deadline moves later and never raises it', function (TicketPriority $from, EscalationLevel $level, string $now, TicketPriority $to, EscalationLevel $expected): void {
        $calculator = slaCalculator();
        $ticket = slaTicket($calculator, $from, '2026-11-01 09:00:00', ['escalation_level' => $level]);

        $ticket->priority = $to;
        $calculator->recalculateForPriority($ticket, slaAt($now));

        expect($ticket->escalation_level)->toBe($expected);
    })->with([
        'approaching High -> Low: back to none' => [TicketPriority::High, EscalationLevel::Approaching, '2026-11-01 15:30:00', TicketPriority::Low, EscalationLevel::None],
        'breached Critical -> High, now approaching' => [TicketPriority::Critical, EscalationLevel::Breached, '2026-11-01 15:30:00', TicketPriority::High, EscalationLevel::Approaching],
        'breached Critical -> High, now within' => [TicketPriority::Critical, EscalationLevel::Breached, '2026-11-01 13:00:00', TicketPriority::High, EscalationLevel::None],
        'breached Critical -> High, still breached' => [TicketPriority::Critical, EscalationLevel::Breached, '2026-11-02 10:00:00', TicketPriority::High, EscalationLevel::Breached],
        'within Medium -> High, already past due: not raised (sla:scan escalates)' => [TicketPriority::Medium, EscalationLevel::None, '2026-11-02 10:00:00', TicketPriority::High, EscalationLevel::None],
    ]);
});

describe('levelFor', function (): void {
    it('reflects the SLA clock, frozen while paused', function (?string $pausedAt, string $now, EscalationLevel $expected): void {
        $calculator = slaCalculator();
        $ticket = slaTicket($calculator, TicketPriority::Critical, '2026-11-01 09:00:00');

        if ($pausedAt !== null) {
            $calculator->pause($ticket, slaAt($pausedAt));
        }

        expect($calculator->levelFor($ticket, slaAt($now)))->toBe($expected)
            ->and($ticket->escalation_level)->toBe(EscalationLevel::None);
    })->with([
        'before warning' => [null, '2026-11-01 10:29:00', EscalationLevel::None],
        'warning reached exactly' => [null, '2026-11-01 10:30:00', EscalationLevel::Approaching],
        'between warning and due' => [null, '2026-11-01 10:59:59', EscalationLevel::Approaching],
        'due reached exactly' => [null, '2026-11-01 11:00:00', EscalationLevel::Breached],
        'long after due' => [null, '2026-11-09 09:00:00', EscalationLevel::Breached],
        'paused before warning: clock frozen' => ['2026-11-01 10:00:00', '2026-11-09 09:00:00', EscalationLevel::None],
        'paused while approaching' => ['2026-11-01 10:45:00', '2026-11-09 09:00:00', EscalationLevel::Approaching],
        'paused after the breach' => ['2026-11-01 11:30:00', '2026-11-09 09:00:00', EscalationLevel::Breached],
    ]);
});
