<?php

declare(strict_types=1);

use App\Actions\Admin\UpdateSlaSettings;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tickets\TicketFilter;
use App\Services\Tickets\TicketNumberGenerator;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    config(['business_hours.timezone' => 'UTC', 'tickets.number_prefix' => 'TKT']);
    $this->storeTimezone = function (string $timezone): void {
        app(UpdateSlaSettings::class)->handle([
            'resolution_minutes' => ['low' => 2880, 'medium' => 1440, 'high' => 480, 'critical' => 120],
            'warning_threshold_percent' => 75,
            'timezone' => $timezone,
            'week' => ['monday' => [['start' => '09:00', 'end' => '17:00']]],
        ]);
        app()->forgetScopedInstances();
    };
});

it('numbers a ticket by the year of the stored business timezone', function (?string $stored, string $expectedYear): void {
    if ($stored !== null) {
        ($this->storeTimezone)($stored);
    }

    $number = app(TicketNumberGenerator::class)->next(CarbonImmutable::parse('2026-12-31 20:00:00', 'UTC'));

    expect($number)->toStartWith("TKT-{$expectedYear}-");
})->with([
    'config timezone when nothing is stored' => [null, '2026'],
    'stored timezone already in the new year' => ['Asia/Dhaka', '2027'],
]);

it('applies created date filters on day boundaries of the stored business timezone', function (?string $stored, bool $included): void {
    if ($stored !== null) {
        ($this->storeTimezone)($stored);
    }
    $ticket = Ticket::factory()->create(['created_at' => CarbonImmutable::parse('2026-10-10 20:00:00', 'UTC')]);

    $numbers = app(TicketFilter::class)
        ->apply(Ticket::query(), ['created_from' => '2026-10-11', 'created_to' => '2026-10-11'], User::factory()->admin()->create(), CarbonImmutable::now())
        ->pluck('number')
        ->all();

    expect($numbers)->toBe($included ? [$ticket->number] : []);
})->with([
    'config timezone when nothing is stored' => [null, false],
    'stored timezone where it is already the next day' => ['Asia/Dhaka', true],
]);
