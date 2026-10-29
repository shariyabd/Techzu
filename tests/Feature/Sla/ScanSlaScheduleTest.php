<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;

it('schedules sla:scan every minute, without overlapping, on one server', function (): void {
    $this->app->make(ConsoleKernel::class)->bootstrap();

    $events = collect($this->app->make(Schedule::class)->events())
        ->filter(static fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'sla:scan'))
        ->values();

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue()
        ->and($events->first()->onOneServer)->toBeTrue();
});
