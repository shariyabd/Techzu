<?php

declare(strict_types=1);

namespace App\Services\Sla;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use RuntimeException;

final class BusinessCalendar
{
    private const MAX_LOOKAHEAD_DAYS = 366;

    private const SECONDS_PER_MINUTE = 60;

    private const SECONDS_PER_HOUR = 3600;

    private const SECONDS_PER_DAY = 86400;

    private const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /** @var array<string, list<array{0: int, 1: int}>> */
    private readonly array $windows;

    /** @var array<string, true> */
    private readonly array $holidays;

    private readonly string $timezone;

    /**
     * @param  array<string, list<array{0:string,1:string}>>  $week
     * @param  list<string>  $holidays
     */
    public function __construct(array $week, string $timezone, array $holidays = [])
    {
        try {
            new DateTimeZone($timezone);
        } catch (Exception) {
            throw new InvalidArgumentException("Unknown business timezone [{$timezone}].");
        }

        $this->timezone = $timezone;
        $this->windows = self::parseWeek($week);
        $this->holidays = self::parseHolidays($holidays);
    }

    public function addBusinessMinutes(CarbonImmutable $from, int $minutes): CarbonImmutable
    {
        if ($minutes < 0) {
            throw new InvalidArgumentException('Business minutes must not be negative.');
        }

        $from = $from->startOfSecond();

        if ($minutes === 0) {
            return $from->utc();
        }

        $local = $from->setTimezone($this->timezone);
        $day = self::anchor($local);
        $cursor = self::secondOfDay($local);
        $remaining = $minutes * self::SECONDS_PER_MINUTE;
        $idleDays = 0;

        while (true) {
            $consumed = false;

            foreach ($this->windowsOn($day) as [$start, $end]) {
                if ($cursor >= $end) {
                    continue;
                }

                $begin = max($cursor, $start);
                $available = $end - $begin;

                if ($remaining <= $available) {
                    return self::toUtc($day, $begin + $remaining);
                }

                $remaining -= $available;
                $cursor = $end;
                $consumed = true;
            }

            $idleDays = $consumed ? 0 : $idleDays + 1;

            if ($idleDays >= self::MAX_LOOKAHEAD_DAYS) {
                throw new RuntimeException(sprintf(
                    'No business window within %d days after %s; check config/business_hours.php and the holidays table.',
                    self::MAX_LOOKAHEAD_DAYS,
                    $from->utc()->toIso8601String(),
                ));
            }

            $day = $day->addDay();
            $cursor = 0;
        }
    }

    public function businessMinutesBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $from = $from->startOfSecond();
        $to = $to->startOfSecond();

        if ($to->lessThanOrEqualTo($from)) {
            return 0;
        }

        $localFrom = $from->setTimezone($this->timezone);
        $localTo = $to->setTimezone($this->timezone);
        $firstDate = $localFrom->format('Y-m-d');
        $lastDate = $localTo->format('Y-m-d');
        $fromSecond = self::secondOfDay($localFrom);
        $toSecond = self::secondOfDay($localTo);

        $seconds = 0;
        $day = self::anchor($localFrom);

        while (($date = $day->format('Y-m-d')) <= $lastDate) {
            $lower = $date === $firstDate ? $fromSecond : 0;
            $upper = $date === $lastDate ? $toSecond : self::SECONDS_PER_DAY;

            foreach ($this->windowsOn($day) as [$start, $end]) {
                $overlap = min($end, $upper) - max($start, $lower);

                if ($overlap > 0) {
                    $seconds += $overlap;
                }
            }

            $day = $day->addDay();
        }

        return intdiv($seconds, self::SECONDS_PER_MINUTE);
    }

    public function isBusinessTime(CarbonImmutable $at): bool
    {
        $local = $at->setTimezone($this->timezone);
        $second = self::secondOfDay($local);

        foreach ($this->windowsOn($local) as [$start, $end]) {
            if ($second >= $start && $second < $end) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{0: int, 1: int}> */
    private function windowsOn(CarbonImmutable $localDay): array
    {
        if (isset($this->holidays[$localDay->format('Y-m-d')])) {
            return [];
        }

        return $this->windows[strtolower($localDay->format('l'))] ?? [];
    }

    private static function anchor(CarbonImmutable $local): CarbonImmutable
    {
        return $local->setTime(12, 0, 0);
    }

    private static function secondOfDay(CarbonImmutable $local): int
    {
        return ($local->hour * self::SECONDS_PER_HOUR) + ($local->minute * self::SECONDS_PER_MINUTE) + $local->second;
    }

    private static function toUtc(CarbonImmutable $day, int $secondOfDay): CarbonImmutable
    {
        return $day
            ->setTime(intdiv($secondOfDay, self::SECONDS_PER_HOUR), intdiv($secondOfDay % self::SECONDS_PER_HOUR, self::SECONDS_PER_MINUTE), $secondOfDay % self::SECONDS_PER_MINUTE)
            ->utc();
    }

    /**
     * @param  array<string, list<array{0:string,1:string}>>  $week
     * @return array<string, list<array{0: int, 1: int}>>
     */
    private static function parseWeek(array $week): array
    {
        $parsed = array_fill_keys(self::WEEKDAYS, []);

        foreach ($week as $weekday => $windows) {
            if (! in_array($weekday, self::WEEKDAYS, true)) {
                throw new InvalidArgumentException("Unknown weekday [{$weekday}] in business hours; use lowercase English names.");
            }

            $dayWindows = [];

            foreach ($windows as [$start, $end]) {
                $startSecond = self::parseTime($start);
                $endSecond = self::parseTime($end);

                if ($startSecond >= $endSecond) {
                    throw new InvalidArgumentException("Business window [{$start}-{$end}] on {$weekday} must end after it starts.");
                }

                $dayWindows[] = [$startSecond, $endSecond];
            }

            usort($dayWindows, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

            for ($i = 1, $count = count($dayWindows); $i < $count; $i++) {
                if ($dayWindows[$i][0] < $dayWindows[$i - 1][1]) {
                    throw new InvalidArgumentException("Business windows on {$weekday} overlap.");
                }
            }

            $parsed[$weekday] = $dayWindows;
        }

        return $parsed;
    }

    private static function parseTime(string $time): int
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $matches) !== 1) {
            throw new InvalidArgumentException("Invalid business time [{$time}]; expected HH:MM between 00:00 and 23:59.");
        }

        return ((int) $matches[1] * self::SECONDS_PER_HOUR) + ((int) $matches[2] * self::SECONDS_PER_MINUTE);
    }

    /**
     * @param  list<string>  $holidays
     * @return array<string, true>
     */
    private static function parseHolidays(array $holidays): array
    {
        $set = [];

        foreach ($holidays as $date) {
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                throw new InvalidArgumentException("Invalid holiday date [{$date}]; expected Y-m-d.");
            }

            $set[$date] = true;
        }

        return $set;
    }
}
