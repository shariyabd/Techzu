<?php

declare(strict_types=1);

use App\Enums\TicketPriority;
use App\Models\Holiday;
use App\Services\Sla\HolidayRepository;
use App\Services\Sla\SlaCalculator;
use Carbon\CarbonImmutable;

it('resolves a calculator that reproduces the PRD example from config', function (): void {
    $deadlines = app(SlaCalculator::class)->deadlinesFor(
        TicketPriority::Critical,
        CarbonImmutable::parse('2026-10-29 17:00:00', 'Asia/Dhaka'),
    );

    expect($deadlines->dueAt->format('Y-m-d H:i:s'))->toBe('2026-11-01 04:00:00')
        ->and($deadlines->dueAt->setTimezone('Asia/Dhaka')->format('l H:i'))->toBe('Sunday 10:00')
        ->and($deadlines->warningAt->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-11-01 09:30');
});

it('builds the calendar with holidays from the database', function (): void {
    Holiday::factory()->create(['date' => '2026-11-01', 'name' => 'Office closure']);
    app(HolidayRepository::class)->flush();
    app()->forgetScopedInstances();

    $deadlines = app(SlaCalculator::class)->deadlinesFor(
        TicketPriority::Critical,
        CarbonImmutable::parse('2026-10-29 17:00:00', 'Asia/Dhaka'),
    );

    expect($deadlines->dueAt->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-11-02 10:00');
});
