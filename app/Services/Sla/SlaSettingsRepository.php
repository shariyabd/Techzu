<?php

declare(strict_types=1);

namespace App\Services\Sla;

use App\Models\BusinessHourWindow;
use App\Models\SlaSetting;
use App\Models\SlaTarget;
use Illuminate\Contracts\Config\Repository as Config;

final class SlaSettingsRepository
{
    private const CACHE_KEY = 'sla.settings';

    public function __construct(
        private readonly SlaRulesCache $cache,
        private readonly Config $config,
    ) {}

    public function current(): SlaSettings
    {
        /** @var array{timezone: string, warning_threshold_percent: int, resolution_minutes: array<string, int>, week: array<string, list<array{0: string, 1: string}>>}|false $stored */
        $stored = $this->cache->remember(self::CACHE_KEY, fn (): array|false => $this->load());

        if ($stored === false) {
            return $this->defaults();
        }

        $defaults = $this->defaults();

        return new SlaSettings(
            resolutionMinutes: [...$defaults->resolutionMinutes, ...$stored['resolution_minutes']],
            warningThresholdPercent: $stored['warning_threshold_percent'],
            timezone: $stored['timezone'],
            week: self::fullWeek($stored['week']),
            stored: true,
        );
    }

    public function timezone(): string
    {
        return $this->current()->timezone;
    }

    public function defaults(): SlaSettings
    {
        /** @var array<string, int|string> $minutes */
        $minutes = $this->config->get('sla.resolution_minutes', []);

        /** @var array<string, array<array-key, array{0: string, 1: string}>> $week */
        $week = $this->config->get('business_hours.week', []);

        return new SlaSettings(
            resolutionMinutes: array_map(static fn (int|string $value): int => (int) $value, $minutes),
            warningThresholdPercent: (int) $this->config->get('sla.warning_threshold_percent'),
            timezone: (string) $this->config->get('business_hours.timezone'),
            week: self::fullWeek($week),
            stored: false,
        );
    }

    public function flush(): void
    {
        $this->cache->invalidate(self::CACHE_KEY);
    }

    /** @return array{timezone: string, warning_threshold_percent: int, resolution_minutes: array<string, int>, week: array<string, list<array{0: string, 1: string}>>}|false */
    private function load(): array|false
    {
        $settings = SlaSetting::query()->find(SlaSetting::SINGLETON_ID, ['id', 'timezone', 'warning_threshold_percent']);

        if ($settings === null) {
            return false;
        }

        $minutes = [];

        foreach (SlaTarget::query()->get(['priority', 'resolution_minutes']) as $target) {
            $minutes[$target->priority->value] = $target->resolution_minutes;
        }

        $week = [];

        foreach (BusinessHourWindow::query()->orderBy('weekday')->orderBy('starts_at')->get(['weekday', 'starts_at', 'ends_at']) as $window) {
            $week[$window->weekday][] = [$window->starts_at, $window->ends_at];
        }

        return [
            'timezone' => $settings->timezone,
            'warning_threshold_percent' => $settings->warning_threshold_percent,
            'resolution_minutes' => $minutes,
            'week' => $week,
        ];
    }

    /**
     * @param  array<string, array<array-key, array{0: string, 1: string}>>  $week
     * @return array<string, list<array{0: string, 1: string}>>
     */
    private static function fullWeek(array $week): array
    {
        $full = [];

        foreach (SlaSettings::WEEKDAYS as $weekday) {
            $full[$weekday] = array_values($week[$weekday] ?? []);
        }

        return $full;
    }
}
