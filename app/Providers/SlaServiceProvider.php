<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Sla\BusinessCalendar;
use App\Services\Sla\HolidayRepository;
use App\Services\Sla\SlaCalculator;
use App\Services\Sla\SlaClock;
use App\Services\Sla\SlaSettingsRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class SlaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(BusinessCalendar::class, static function (Application $app): BusinessCalendar {
            $settings = $app->make(SlaSettingsRepository::class)->current();

            return new BusinessCalendar(
                $settings->week,
                $settings->timezone,
                $app->make(HolidayRepository::class)->dates(),
            );
        });

        $this->app->scoped(SlaCalculator::class, static function (Application $app): SlaCalculator {
            $settings = $app->make(SlaSettingsRepository::class)->current();

            return new SlaCalculator(
                $app->make(BusinessCalendar::class),
                $settings->resolutionMinutes,
                $settings->warningThresholdPercent,
            );
        });

        $this->app->scoped(SlaClock::class, static fn (Application $app): SlaClock => new SlaClock($app->make(BusinessCalendar::class)));
    }
}
