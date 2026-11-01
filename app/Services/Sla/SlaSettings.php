<?php

declare(strict_types=1);

namespace App\Services\Sla;

final readonly class SlaSettings
{
    public const WEEKDAYS = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

    /**
     * @param  array<string, int>  $resolutionMinutes
     * @param  array<string, list<array{0: string, 1: string}>>  $week
     */
    public function __construct(
        public array $resolutionMinutes,
        public int $warningThresholdPercent,
        public string $timezone,
        public array $week,
        public bool $stored,
    ) {}

    /**
     * @return array{resolution_minutes: array<string, int>, warning_threshold_percent: int, timezone: string, week: array<string, list<array{start: string, end: string}>>}
     */
    public function toInput(): array
    {
        $week = [];

        foreach (self::WEEKDAYS as $weekday) {
            $week[$weekday] = array_map(
                static fn (array $window): array => ['start' => $window[0], 'end' => $window[1]],
                $this->week[$weekday] ?? [],
            );
        }

        return [
            'resolution_minutes' => $this->resolutionMinutes,
            'warning_threshold_percent' => $this->warningThresholdPercent,
            'timezone' => $this->timezone,
            'week' => $week,
        ];
    }
}
