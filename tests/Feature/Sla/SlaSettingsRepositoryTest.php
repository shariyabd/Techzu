<?php

declare(strict_types=1);

use App\Actions\Admin\UpdateSlaSettings;
use App\Enums\TicketPriority;
use App\Services\Sla\SlaCalculator;
use Carbon\CarbonImmutable;

it('builds the SLA calculator from config when no settings are stored', function (): void {
    config([
        'sla.resolution_minutes.critical' => 60,
        'business_hours.week.thursday' => [['09:00', '17:30']],
    ]);
    app()->forgetScopedInstances();

    $deadlines = app(SlaCalculator::class)->deadlinesFor(TicketPriority::Critical, CarbonImmutable::parse('2026-10-29 17:00', 'Asia/Dhaka'));

    expect($deadlines->dueAt->setTimezone('Asia/Dhaka')->format('l H:i'))->toBe('Sunday 09:30');
});

it('builds the SLA calculator from stored settings instead of config', function (): void {
    app(UpdateSlaSettings::class)->handle([
        'resolution_minutes' => ['low' => 2880, 'medium' => 1440, 'high' => 480, 'critical' => 180],
        'warning_threshold_percent' => 50,
        'timezone' => 'Europe/London',
        'week' => ['friday' => [['start' => '10:00', 'end' => '12:00'], ['start' => '14:00', 'end' => '16:00']]],
    ]);
    app()->forgetScopedInstances();

    $deadlines = app(SlaCalculator::class)->deadlinesFor(TicketPriority::Critical, CarbonImmutable::parse('2026-10-30 09:00', 'Europe/London'));

    expect($deadlines->dueAt->setTimezone('Europe/London')->format('Y-m-d H:i'))->toBe('2026-10-30 15:00')
        ->and($deadlines->warningAt->setTimezone('Europe/London')->format('Y-m-d H:i'))->toBe('2026-10-30 11:30');
});
