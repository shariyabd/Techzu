<?php

declare(strict_types=1);

use App\Models\Holiday;
use App\Services\Sla\HolidayRepository;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

it('returns holiday dates as sorted Y-m-d strings', function (): void {
    Holiday::factory()->create(['date' => '2026-12-16', 'name' => 'Victory Day']);
    Holiday::factory()->create(['date' => '2026-11-01', 'name' => 'Office closure']);

    expect(app(HolidayRepository::class)->dates())->toBe(['2026-11-01', '2026-12-16']);
});

it('caches the dates forever under sla.holidays until flushed', function (): void {
    $repository = app(HolidayRepository::class);
    Holiday::factory()->create(['date' => '2026-11-01', 'name' => 'Office closure']);
    $holidayQueries = 0;
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$holidayQueries): void {
        if (str_contains($query->sql, 'holidays')) {
            $holidayQueries++;
        }
    });

    expect($repository->dates())->toBe(['2026-11-01'])
        ->and($repository->dates())->toBe(['2026-11-01'])
        ->and($holidayQueries)->toBe(1)
        ->and(Cache::has('sla.holidays'))->toBeTrue();

    Holiday::factory()->create(['date' => '2026-11-05', 'name' => 'Added later']);

    expect($repository->dates())->toBe(['2026-11-01']);

    $repository->flush();

    expect(Cache::has('sla.holidays'))->toBeFalse()
        ->and($repository->dates())->toBe(['2026-11-01', '2026-11-05']);
});
