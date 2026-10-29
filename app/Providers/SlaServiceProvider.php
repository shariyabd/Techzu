<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Sla\BusinessCalendar;
use App\Services\Sla\HolidayRepository;
use App\Services\Sla\SlaCalculator;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class SlaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(BusinessCalendar::class, static function (Application $app): BusinessCalendar {
            $config = $app->make(Config::class);

            /** @var array<string, list<array{0: string, 1: string}>> $week */
            $week = $config->get('business_hours.week', []);

            return new BusinessCalendar(
                $week,
                (string) $config->get('business_hours.timezone'),
                $app->make(HolidayRepository::class)->dates(),
            );
        });

        $this->app->scoped(SlaCalculator::class, static function (Application $app): SlaCalculator {
            $config = $app->make(Config::class);

            /** @var array<string, int> $minutes */
            $minutes = $config->get('sla.resolution_minutes', []);

            return new SlaCalculator(
                $app->make(BusinessCalendar::class),
                $minutes,
                (int) $config->get('sla.warning_threshold_percent'),
            );
        });
    }
}
