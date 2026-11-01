<?php

declare(strict_types=1);

namespace App\Services\Sla;

use App\Enums\TicketPriority;
use DateTimeZone;

final class SlaSettingsRules
{
    public const MIN_TARGET_MINUTES = 1;

    public const MAX_TARGET_MINUTES = 43200;

    public const MIN_THRESHOLD_PERCENT = 1;

    public const MAX_THRESHOLD_PERCENT = 99;

    public const MAX_WINDOWS_PER_DAY = 6;

    public const TIME_PATTERN = '/^(([01]\d|2[0-3]):[0-5]\d|24:00)$/';

    public const END_OF_DAY = '24:00';

    /**
     * @param  array{resolution_minutes: array<string, int>, warning_threshold_percent: int, timezone: string, week: array<string, list<array{start: string, end: string}>>}  $data
     * @return array<string, string>
     */
    public static function violations(array $data): array
    {
        $violations = [];

        foreach (TicketPriority::cases() as $priority) {
            $minutes = $data['resolution_minutes'][$priority->value] ?? null;

            if (! is_int($minutes) || $minutes < self::MIN_TARGET_MINUTES || $minutes > self::MAX_TARGET_MINUTES) {
                $violations["resolution_minutes.{$priority->value}"] = sprintf(
                    'The %s target must be between %d and %d business minutes.',
                    $priority->label(),
                    self::MIN_TARGET_MINUTES,
                    self::MAX_TARGET_MINUTES,
                );
            }
        }

        $threshold = $data['warning_threshold_percent'];

        if ($threshold < self::MIN_THRESHOLD_PERCENT || $threshold > self::MAX_THRESHOLD_PERCENT) {
            $violations['warning_threshold_percent'] = sprintf(
                'The warning threshold must be between %d and %d percent.',
                self::MIN_THRESHOLD_PERCENT,
                self::MAX_THRESHOLD_PERCENT,
            );
        }

        if (! in_array($data['timezone'], DateTimeZone::listIdentifiers(), true)) {
            $violations['timezone'] = 'Choose a valid timezone.';
        }

        return [...$violations, ...self::weekViolations($data['week'])];
    }

    /**
     * @param  array<array-key, mixed>  $week
     * @return array<string, string>
     */
    public static function weekViolations(array $week): array
    {
        $violations = [];
        $openWindows = 0;

        foreach (SlaSettings::WEEKDAYS as $weekday) {
            $windows = $week[$weekday] ?? [];

            if (! is_array($windows)) {
                continue;
            }

            $parsed = [];

            foreach ($windows as $index => $window) {
                $start = is_array($window) ? ($window['start'] ?? null) : null;
                $end = is_array($window) ? ($window['end'] ?? null) : null;

                if (! self::isTime($start) || ! self::isTime($end)) {
                    $violations["week.{$weekday}.{$index}.start"] ??= 'Use HH:MM times between 00:00 and 24:00.';

                    continue;
                }

                if ($start === self::END_OF_DAY) {
                    $violations["week.{$weekday}.{$index}.start"] = '24:00 can only end a window. Start it at 23:59 or earlier.';

                    continue;
                }

                if ($start >= $end) {
                    $violations["week.{$weekday}.{$index}.end"] = 'The window must end after it starts.';

                    continue;
                }

                $parsed[] = ['index' => $index, 'start' => $start, 'end' => $end];
            }

            usort($parsed, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

            for ($i = 1, $count = count($parsed); $i < $count; $i++) {
                if ($parsed[$i]['start'] < $parsed[$i - 1]['end']) {
                    $violations["week.{$weekday}.{$parsed[$i]['index']}.start"] = 'This window overlaps another window on the same day.';
                }
            }

            $openWindows += count($parsed);
        }

        if ($openWindows === 0 && $violations === []) {
            $violations['week'] = 'Open at least one business window in the week.';
        }

        return $violations;
    }

    private static function isTime(mixed $value): bool
    {
        return is_string($value) && preg_match(self::TIME_PATTERN, $value) === 1;
    }
}
