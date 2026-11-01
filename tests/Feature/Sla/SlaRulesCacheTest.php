<?php

declare(strict_types=1);

use App\Actions\Admin\CreateHoliday;
use App\Actions\Admin\UpdateSlaSettings;
use App\Events\SlaRulesChanged;
use App\Models\Holiday;
use App\Models\SlaSetting;
use App\Services\Sla\HolidayRepository;
use App\Services\Sla\SlaSettingsRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

function slaRulesPayload(string $timezone): array
{
    return [
        'resolution_minutes' => ['low' => 1200, 'medium' => 600, 'high' => 240, 'critical' => 60],
        'warning_threshold_percent' => 80,
        'timezone' => $timezone,
        'week' => ['monday' => [['start' => '08:00', 'end' => '17:00']]],
    ];
}

it('does not keep a stale value written by a cold-cache reader that loaded the old rows before the change', function (Closure $arrange, string $model, Closure $change, Closure $read, mixed $expected): void {
    Event::fake([SlaRulesChanged::class]);
    $arrange();
    Cache::flush();
    $changed = false;
    $model::retrieved(function () use (&$changed, $change): void {
        if (! $changed) {
            $changed = true;
            $change();
        }
    });

    $read();

    expect($changed)->toBeTrue()
        ->and($read())->toBe($expected);
})->with([
    'holidays' => [
        fn () => Holiday::factory()->create(['date' => '2026-12-16', 'name' => 'Victory Day']),
        Holiday::class,
        fn () => app(CreateHoliday::class)->handle(['date' => '2026-02-21', 'name' => 'Language Day']),
        fn (): array => app(HolidayRepository::class)->dates(),
        ['2026-02-21', '2026-12-16'],
    ],
    'sla settings' => [
        fn () => app(UpdateSlaSettings::class)->handle(slaRulesPayload('Europe/London')),
        SlaSetting::class,
        fn () => app(UpdateSlaSettings::class)->handle(slaRulesPayload('Asia/Tokyo')),
        fn (): string => app(SlaSettingsRepository::class)->current()->timezone,
        'Asia/Tokyo',
    ],
]);
