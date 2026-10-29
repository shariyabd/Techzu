<?php

declare(strict_types=1);

use App\Services\Sla\BusinessCalendar;
use Carbon\CarbonImmutable;

/** @return array<string, list<array{0: string, 1: string}>> */
function bcStandardWeek(): array
{
    $day = [['09:00', '18:00']];

    return [
        'sunday' => $day,
        'monday' => $day,
        'tuesday' => $day,
        'wednesday' => $day,
        'thursday' => $day,
        'friday' => [],
        'saturday' => [],
    ];
}

/** @return array<string, list<array{0: string, 1: string}>> */
function bcLunchWeek(): array
{
    $day = [['14:00', '18:00'], ['09:00', '13:00']];

    return [
        'sunday' => $day,
        'monday' => $day,
        'tuesday' => $day,
        'wednesday' => $day,
        'thursday' => $day,
        'friday' => [],
        'saturday' => [],
    ];
}

/**
 * @param  list<string>  $holidays
 * @param  array<string, list<array{0: string, 1: string}>>|null  $week
 */
function bcCalendar(array $holidays = [], ?array $week = null, string $timezone = 'Asia/Dhaka'): BusinessCalendar
{
    return new BusinessCalendar($week ?? bcStandardWeek(), $timezone, $holidays);
}

/**
 * @return list<string>
 */
function bcHolidayRun(string $from, int $days): array
{
    $start = CarbonImmutable::parse($from);

    return array_map(static fn (int $offset): string => $start->addDays($offset)->format('Y-m-d'), range(0, $days - 1));
}

function bcAt(string $local, string $timezone = 'Asia/Dhaka'): CarbonImmutable
{
    return CarbonImmutable::parse($local, $timezone)->utc();
}

function bcLocal(CarbonImmutable $instant, string $timezone = 'Asia/Dhaka'): string
{
    return $instant->setTimezone($timezone)->format('Y-m-d H:i:s');
}

describe('addBusinessMinutes', function (): void {
    it('steps through business windows', function (string $start, int $minutes, array $holidays, string $expected): void {
        $result = bcCalendar($holidays)->addBusinessMinutes(bcAt($start), $minutes);

        expect(bcLocal($result))->toBe($expected)
            ->and($result->tzName)->toBe('UTC');
    })->with([
        'PRD example: Critical, Thu 17:00 -> Sun 10:00' => ['2026-10-29 17:00:00', 120, [], '2026-11-01 10:00:00'],
        'inside a single window' => ['2026-11-01 10:00:00', 120, [], '2026-11-01 12:00:00'],
        'start before opening' => ['2026-11-01 07:30:00', 120, [], '2026-11-01 11:00:00'],
        'start after closing' => ['2026-10-28 19:00:00', 120, [], '2026-10-29 11:00:00'],
        'start on a Friday' => ['2026-10-30 12:00:00', 120, [], '2026-11-01 11:00:00'],
        'start on a Saturday night' => ['2026-10-31 23:00:00', 60, [], '2026-11-01 10:00:00'],
        'holiday on the next working day' => ['2026-10-29 17:00:00', 120, ['2026-11-01'], '2026-11-02 10:00:00'],
        'two consecutive holidays' => ['2026-10-29 17:00:00', 120, ['2026-11-01', '2026-11-02'], '2026-11-03 10:00:00'],
        'start on a holiday' => ['2026-11-01 10:00:00', 120, ['2026-11-01'], '2026-11-02 11:00:00'],
        'boundary: 17:59 + 1 min ends at 18:00' => ['2026-10-29 17:59:00', 1, [], '2026-10-29 18:00:00'],
        'boundary: start at 18:00 rolls to the next opening' => ['2026-10-29 18:00:00', 1, [], '2026-11-01 09:01:00'],
        'boundary: a full window from opening ends at closing' => ['2026-11-01 09:00:00', 540, [], '2026-11-01 18:00:00'],
        'boundary: a full window + 1 min' => ['2026-11-01 09:00:00', 541, [], '2026-11-02 09:01:00'],
        'seconds are preserved' => ['2026-10-29 17:59:30', 1, [], '2026-11-01 09:00:30'],
        'Low 48h ticket spans more than one week' => ['2026-10-29 17:00:00', 2880, [], '2026-11-08 11:00:00'],
    ]);

    it('handles several windows per day (lunch break)', function (string $start, int $minutes, string $expected): void {
        $result = bcCalendar([], bcLunchWeek())->addBusinessMinutes(bcAt($start), $minutes);

        expect(bcLocal($result))->toBe($expected);
    })->with([
        'spans the lunch break' => ['2026-11-01 12:30:00', 60, '2026-11-01 14:30:00'],
        'ends exactly when lunch starts' => ['2026-11-01 12:00:00', 60, '2026-11-01 13:00:00'],
        'starts during lunch' => ['2026-11-01 13:30:00', 30, '2026-11-01 14:30:00'],
        'one minute either side of lunch' => ['2026-11-01 12:59:00', 2, '2026-11-01 14:01:00'],
        'afternoon into the next day' => ['2026-11-01 17:30:00', 60, '2026-11-02 09:30:00'],
        'Thu afternoon into Sunday' => ['2026-10-29 17:00:00', 120, '2026-11-01 10:00:00'],
    ]);

    it('returns the start itself, in UTC, for zero minutes', function (string $start): void {
        $from = CarbonImmutable::parse($start, 'Asia/Dhaka');

        $result = bcCalendar()->addBusinessMinutes($from, 0);

        expect($result->tzName)->toBe('UTC')
            ->and($result->equalTo($from))->toBeTrue();
    })->with([
        'inside business hours' => '2026-10-29 10:00:00',
        'on a closed Friday' => '2026-10-30 12:00:00',
        'exactly at closing' => '2026-10-29 18:00:00',
    ]);

    it('accepts a non-UTC input timezone and returns UTC', function (string $timezone): void {
        $from = CarbonImmutable::parse('2026-10-29 17:00:00', 'Asia/Dhaka')->setTimezone($timezone);

        $result = bcCalendar()->addBusinessMinutes($from, 120);

        expect($result->tzName)->toBe('UTC')
            ->and($result->format('Y-m-d H:i:s'))->toBe('2026-11-01 04:00:00');
    })->with(['America/New_York']);

    it('uses wall-clock windows across a DST change', function (): void {
        $week = [
            'monday' => [['09:00', '17:00']],
            'tuesday' => [['09:00', '17:00']],
            'wednesday' => [['09:00', '17:00']],
            'thursday' => [['09:00', '17:00']],
            'friday' => [['09:00', '17:00']],
        ];
        $calendar = new BusinessCalendar($week, 'Europe/London');
        $from = bcAt('2026-10-23 16:00:00', 'Europe/London');

        $result = $calendar->addBusinessMinutes($from, 120);

        expect($from->format('Y-m-d H:i:s'))->toBe('2026-10-23 15:00:00')
            ->and(bcLocal($result, 'Europe/London'))->toBe('2026-10-26 10:00:00')
            ->and($result->format('Y-m-d H:i:s'))->toBe('2026-10-26 10:00:00')
            ->and($calendar->businessMinutesBetween($from, $result))->toBe(120);
    });

    it('rejects negative minutes', function (): void {
        expect(fn () => bcCalendar()->addBusinessMinutes(bcAt('2026-10-29 10:00:00'), -1))
            ->toThrow(InvalidArgumentException::class, 'must not be negative');
    });

    it('throws when the schedule has no usable window (safety cap)', function (array $week, array $holidays): void {
        $calendar = new BusinessCalendar($week, 'Asia/Dhaka', $holidays);

        expect(fn () => $calendar->addBusinessMinutes(bcAt('2026-10-29 10:00:00'), 60))
            ->toThrow(RuntimeException::class, 'No business window within 366 days');
    })->with([
        'empty week' => [[], []],
        'a full year of holidays' => [array_fill_keys(['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'], [['09:00', '18:00']]), bcHolidayRun('2026-10-29', 366)],
    ]);

    it('still finds a window after 365 consecutive closed days', function (): void {
        $week = array_fill_keys(['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'], [['09:00', '18:00']]);
        $calendar = new BusinessCalendar($week, 'Asia/Dhaka', bcHolidayRun('2026-10-29', 365));

        expect(bcLocal($calendar->addBusinessMinutes(bcAt('2026-10-29 10:00:00'), 60)))->toBe('2027-10-29 10:00:00');
    });
});

describe('businessMinutesBetween', function (): void {
    it('counts only business time', function (string $from, string $to, array $holidays, int $expected): void {
        expect(bcCalendar($holidays)->businessMinutesBetween(bcAt($from), bcAt($to)))->toBe($expected);
    })->with([
        'PRD example span' => ['2026-10-29 17:00:00', '2026-11-01 10:00:00', [], 120],
        'entirely on the weekend' => ['2026-10-30 08:00:00', '2026-10-31 20:00:00', [], 0],
        'closing to the next opening' => ['2026-10-29 18:00:00', '2026-11-01 09:00:00', [], 0],
        'one full working day' => ['2026-11-01 09:00:00', '2026-11-02 09:00:00', [], 540],
        'one full working week' => ['2026-11-01 09:00:00', '2026-11-08 09:00:00', [], 2700],
        'holiday is skipped' => ['2026-10-29 17:00:00', '2026-11-02 10:00:00', ['2026-11-01'], 120],
        'partial minutes are floored' => ['2026-11-01 10:00:00', '2026-11-01 10:01:59', [], 1],
        'same instant' => ['2026-11-01 10:00:00', '2026-11-01 10:00:00', [], 0],
        'reversed range' => ['2026-11-01 11:00:00', '2026-11-01 10:00:00', [], 0],
    ]);

    it('skips the lunch break', function (): void {
        expect(bcCalendar([], bcLunchWeek())->businessMinutesBetween(bcAt('2026-11-01 12:00:00'), bcAt('2026-11-01 15:00:00')))
            ->toBe(120);
    });

    it('is the inverse of addBusinessMinutes for any start', function (string $week, string $start, int $minutes): void {
        $calendar = bcCalendar(['2026-11-03'], $week === 'lunch' ? bcLunchWeek() : bcStandardWeek());
        $from = bcAt($start);

        $due = $calendar->addBusinessMinutes($from, $minutes);

        expect($calendar->businessMinutesBetween($from, $due))->toBe($minutes);
    })->with(['standard', 'lunch'])
        ->with([
            'Thu 17:00' => '2026-10-29 17:00:00',
            'Thu 17:59:30' => '2026-10-29 17:59:30',
            'Fri 12:00 (closed)' => '2026-10-30 12:00:00',
            'Sun 07:30 (before opening)' => '2026-11-01 07:30:00',
            'Wed 19:00 (after closing)' => '2026-10-28 19:00:00',
            'Sun 09:00 (opening)' => '2026-11-01 09:00:00',
        ])
        ->with([1, 59, 60, 61, 120, 539, 540, 541, 1440, 2880, 10000]);

    it('round-trips back to a target that lies in business time', function (string $from, string $to): void {
        $calendar = bcCalendar();
        $start = bcAt($from);

        $minutes = $calendar->businessMinutesBetween($start, bcAt($to));

        expect(bcLocal($calendar->addBusinessMinutes($start, $minutes)))->toBe($to);
    })->with([
        'Thu 17:00 -> Sun 10:00' => ['2026-10-29 17:00:00', '2026-11-01 10:00:00'],
        'Fri 12:00 -> Sun 09:30' => ['2026-10-30 12:00:00', '2026-11-01 09:30:00'],
        'Wed 19:00 -> Thu 11:00' => ['2026-10-28 19:00:00', '2026-10-29 11:00:00'],
        'Thu 17:00 -> Thu 18:00 (closing)' => ['2026-10-29 17:00:00', '2026-10-29 18:00:00'],
        'Sun 09:00 -> next Sun 11:00' => ['2026-11-01 09:00:00', '2026-11-08 11:00:00'],
        'same instant' => ['2026-10-29 10:00:00', '2026-10-29 10:00:00'],
    ]);
});

describe('isBusinessTime', function (): void {
    it('is true only inside a window on a working, non-holiday day', function (string $at, string $week, bool $expected): void {
        $calendar = bcCalendar(['2026-11-02'], $week === 'lunch' ? bcLunchWeek() : bcStandardWeek());

        expect($calendar->isBusinessTime(bcAt($at)))->toBe($expected);
    })->with([
        'at opening' => ['2026-10-29 09:00:00', 'standard', true],
        'one minute before closing' => ['2026-10-29 17:59:00', 'standard', true],
        'at closing (end is exclusive)' => ['2026-10-29 18:00:00', 'standard', false],
        'before opening' => ['2026-10-29 08:59:59', 'standard', false],
        'Friday' => ['2026-10-30 12:00:00', 'standard', false],
        'holiday' => ['2026-11-02 12:00:00', 'standard', false],
        'lunch break' => ['2026-11-01 13:30:00', 'lunch', false],
        'after lunch' => ['2026-11-01 14:00:00', 'lunch', true],
    ]);
});

describe('constructor validation', function (): void {
    it('rejects an invalid configuration', function (array $week, string $timezone, array $holidays): void {
        expect(fn () => new BusinessCalendar($week, $timezone, $holidays))->toThrow(InvalidArgumentException::class);
    })->with([
        'unknown weekday' => [['funday' => [['09:00', '18:00']]], 'Asia/Dhaka', []],
        'malformed time' => [['sunday' => [['9am', '18:00']]], 'Asia/Dhaka', []],
        'window ends before it starts' => [['sunday' => [['18:00', '09:00']]], 'Asia/Dhaka', []],
        'overlapping windows' => [['sunday' => [['09:00', '13:00'], ['12:00', '18:00']]], 'Asia/Dhaka', []],
        'unknown timezone' => [bcStandardWeek(), 'Mars/Olympus_Mons', []],
        'malformed holiday' => [bcStandardWeek(), 'Asia/Dhaka', ['01/11/2026']],
        'impossible holiday date' => [bcStandardWeek(), 'Asia/Dhaka', ['2026-02-30']],
    ]);
});
