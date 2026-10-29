# T02: Business calendar & SLA calculator

> **For agentic workers:** REQUIRED SUB-SKILL: use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax. Work test-first. Read `CLAUDE.md`, the spec (§7) and README §3.4 before starting.

## 1. Header

- **Objective:** Build the pure, window-stepping `BusinessCalendar`, the cached `HolidayRepository`, and the `SlaCalculator` that computes, pauses, resumes and recalculates resolution deadlines, all wired from `config/sla.php` and `config/business_hours.php` by `SlaServiceProvider`.
- **Depends on:** T01 (enums `TicketPriority`, `TicketStatus`, `EscalationLevel`; models `Ticket`, `Holiday`; factories `TicketFactory`, `HolidayFactory`; `tests/Pest.php`).
- **Wave:** 2 (parallel with T03 and T04).
- **TDs implemented:** TD-041 (pure window-stepping calendar), TD-042 (pause/resume by remaining business time), TD-043 (priority change recalculates from the original start), TD-045 (SLA rules live only in `config/sla.php`), TD-023 (store `sla_due_at` and `sla_warning_at`), TD-101 (holidays cached forever, invalidated on change), TD-130 (domain config files).

### Design notes (read before coding)

**Wall-clock arithmetic.** `BusinessCalendar` converts every input instant into the business timezone and then works only with *local calendar dates* and *seconds since local midnight*. A window `['09:00', '18:00']` therefore means 09:00–18:00 on the wall clock of every calendar day, whatever that day's UTC offset is. Results are rebuilt with `setTime()` on the local date and converted back with `->utc()`. This makes the arithmetic DST-agnostic (TD-041): a Friday 16:00 BST ticket with 120 minutes is due Monday 10:00 GMT in Europe/London, even though the clocks went back on Sunday. The day cursor is anchored at local noon, so `addDay()` never lands on a non-existent or repeated midnight.

**Window stepping.** `addBusinessMinutes` walks one window at a time: skip windows that ended before the cursor, start at `max(cursor, windowStart)`, use `min(remaining, windowEnd − start)`, and repeat. A remaining amount that exactly fills a window returns the window's *end* (17:59 + 1 min = 18:00), not the next opening. `businessMinutesBetween` sums window overlaps day by day; it is the inverse of `addBusinessMinutes`. Precision is one second; sub-second parts are dropped. `businessMinutesBetween` floors to whole minutes.

**Safety cap.** If 366 consecutive days pass without any usable window (an empty week, or a year of holidays), `addBusinessMinutes` throws `RuntimeException`. Zero minutes returns the start unchanged (in UTC) and never consults the schedule.

**Escalation level is only ever lowered by the calculator.** `levelFor()` returns the level a ticket *should* have now (the SLA clock is frozen at `sla_paused_at` while paused). `recalculateForPriority()` and `resume()` set `escalation_level = min(current, levelFor(now))`. They never *raise* it: raising would silently swallow the `TicketSlaApproaching` / `TicketSlaBreached` events, because `sla:scan` (T09) dispatches only when it moves the level up itself. So a ticket that becomes breached immediately after a priority upgrade keeps its level, and the next scan escalates it and notifies (TD-043 trade-off).

**Why `SlaCalculatorTest` boots Laravel but never touches the database.** `SlaCalculator` only reads and writes attributes on a `Ticket`; it never saves (README §3.4). The test therefore builds unsaved models with `Ticket::factory()->make([...])`. Two details make that database-free:
1. `Factory::make()` still calls `create()` on nested parent factories (`'category_id' => Category::factory()` in the definition). The test overrides every foreign key (`category_id`, `customer_id`, `created_by_id`, `assigned_agent_id`) with plain values, so no parent factory runs.
2. Eloquent's datetime casts ask the connection's query grammar for the date format, and the factory uses the container's Faker. So the file `uses(Tests\TestCase::class)` to boot the application. Laravel's PDO is lazy, so no connection is opened.
A `beforeEach` listener throws on any `QueryExecuted` event, which proves the "unit tests don't touch the database" rule (CLAUDE.md) instead of assuming it. Using the factory keeps the test in step with T01's real schema and casts, which a hand-rolled `new Ticket()` would not.

**Assigning datetimes to models.** Eloquent stores a Carbon instance by formatting it *in its own timezone*. Every value this task writes to a model is converted with `->utc()` first; the calendar already returns UTC.

## 2. Files

**Create**
- `app/Services/Sla/BusinessCalendar.php`
- `app/Services/Sla/HolidayRepository.php`
- `app/Services/Sla/SlaDeadlines.php`
- `app/Services/Sla/SlaCalculator.php`
- `app/Providers/SlaServiceProvider.php`
- `config/sla.php`
- `config/business_hours.php`
- `tests/Unit/Sla/BusinessCalendarTest.php`
- `tests/Unit/Sla/SlaCalculatorTest.php`
- `tests/Feature/Sla/HolidayRepositoryTest.php`
- `tests/Feature/Sla/SlaServiceProviderTest.php`

**Modify**
- `bootstrap/providers.php`: append `App\Providers\SlaServiceProvider::class` (README §4 allows T02 this one line).

**Must not touch**
- `app/Models/*`, `app/Enums/*`, `database/**` (migrations, factories, seeders): T01 owns them.
- `app/Providers/AppServiceProvider.php` (T00/T04), `bootstrap/app.php`, `tests/Pest.php`, `.env.example`, `phpunit.xml`.
- `app/Services/Sla/SlaQuery.php` (T05) and anything under `app/Actions`, `app/Events`, `app/Listeners`.

## 3. Interfaces

### Consumes
- `App\Enums\TicketPriority` (cases `Low`, `Medium`, `High`, `Critical`, values `low|medium|high|critical`), `App\Enums\TicketStatus`, `App\Enums\EscalationLevel` (int-backed: `None = 0`, `Approaching = 1`, `Breached = 2`). T01.
- `App\Models\Ticket` with casts `priority` → `TicketPriority`, `status` → `TicketStatus`, `escalation_level` → `EscalationLevel`, every `*_at` → `immutable_datetime`; `isPaused(): bool`. T01.
- `App\Models\Holiday` (fillable `date, name`; cast `date` → `immutable_date`) and `HolidayFactory`. T01.
- `Database\Factories\TicketFactory` (`Ticket::factory()->make()`). T01.
- `tests/Pest.php` extends `Tests\TestCase` with `RefreshDatabase` for `Feature` only. T01.

### Produces (README §3.4, verbatim)

```php
// app/Services/Sla
final class BusinessCalendar {
    /** @param array<string, list<array{0:string,1:string}>> $week  lowercase weekday => [['09:00','18:00'], ...]
     *  @param list<string> $holidays  'Y-m-d' dates in business timezone */
    public function __construct(array $week, string $timezone, array $holidays = []);
    public function addBusinessMinutes(CarbonImmutable $from, int $minutes): CarbonImmutable; // returns UTC
    public function businessMinutesBetween(CarbonImmutable $from, CarbonImmutable $to): int;  // 0 if $to <= $from
    public function isBusinessTime(CarbonImmutable $at): bool;
}
final class HolidayRepository { /** @return list<string> */ public function dates(): array; public function flush(): void; } // cache key 'sla.holidays'
final readonly class SlaDeadlines { public function __construct(public CarbonImmutable $dueAt, public CarbonImmutable $warningAt); }
final class SlaCalculator {
    /** @param array<string,int> $resolutionMinutes keyed by TicketPriority value */
    public function __construct(BusinessCalendar $calendar, array $resolutionMinutes, int $warningPercent);
    public function deadlinesFor(TicketPriority $priority, CarbonImmutable $start): SlaDeadlines;
    public function applyInitial(Ticket $ticket, CarbonImmutable $start): void;          // sets sla_started_at, sla_due_at, sla_warning_at (no save)
    public function recalculateForPriority(Ticket $ticket, CarbonImmutable $now): void;  // from sla_started_at; keeps pause; resets escalation_level via levelFor (no save)
    public function pause(Ticket $ticket, CarbonImmutable $now): void;                   // no save
    public function resume(Ticket $ticket, CarbonImmutable $now): void;                  // shifts due/warning; resets escalation_level via levelFor (no save)
    public function levelFor(Ticket $ticket, CarbonImmutable $now): EscalationLevel;     // level the ticket *should* have now
}
// app/Providers/SlaServiceProvider.php binds BusinessCalendar (built from config + HolidayRepository, scoped) and SlaCalculator (singleton per request = scoped).
```

Behavioural contract for later tasks:
- `BusinessCalendar` constructor throws `InvalidArgumentException` for an unknown weekday, a malformed `HH:MM` time, an empty or reversed window, overlapping windows, an unknown timezone or a malformed holiday date. `addBusinessMinutes` throws `InvalidArgumentException` for negative minutes and `RuntimeException` (message starts `No business window within 366 days`) when the schedule has no usable window.
- `SlaCalculator` constructor throws `InvalidArgumentException` if any `TicketPriority` value is missing or not a positive int, or if the percent is outside 0–100. `warningAt = addBusinessMinutes(start, floor(minutes × percent / 100))`.
- `applyInitial` requires `$ticket->priority` to be set. It does not touch `escalation_level` or `sla_paused_at`.
- `pause` is a no-op on an already paused ticket. `resume` is a no-op on an unpaused ticket.
- `recalculateForPriority` and `resume` only ever *lower* `escalation_level` (see Design notes).
- Config keys: `sla.resolution_minutes.{low,medium,high,critical}`, `sla.warning_threshold_percent`, `business_hours.timezone`, `business_hours.week.{sunday..saturday}`.
- `HolidayRepository::flush()` must be called by whoever writes holidays (T13).

## 4. Subtasks & mini-waves

| Subtask | Files | Depends on | Parallel-safe with |
|---|---|---|---|
| 2.1 BusinessCalendar | `app/Services/Sla/BusinessCalendar.php`, `tests/Unit/Sla/BusinessCalendarTest.php` | — | 2.2 |
| 2.2 HolidayRepository | `app/Services/Sla/HolidayRepository.php`, `tests/Feature/Sla/HolidayRepositoryTest.php` | — | 2.1 |
| 2.3 SlaDeadlines + SlaCalculator | `app/Services/Sla/SlaDeadlines.php`, `app/Services/Sla/SlaCalculator.php`, `tests/Unit/Sla/SlaCalculatorTest.php` | 2.1 | — |
| 2.4 Config + provider wiring | `config/sla.php`, `config/business_hours.php`, `app/Providers/SlaServiceProvider.php`, `bootstrap/providers.php`, `tests/Feature/Sla/SlaServiceProviderTest.php` | 2.1, 2.2, 2.3 | — |

Mini-waves: **A** = 2.1 ∥ 2.2 → **B** = 2.3 → **C** = 2.4.

## 5. Subtasks

### 2.1 BusinessCalendar (TD-041)

- [ ] **Step 1: Write the failing test.** Create `tests/Unit/Sla/BusinessCalendarTest.php`:

```php
<?php

declare(strict_types=1);

use App\Services\Sla\BusinessCalendar;
use Carbon\CarbonImmutable;

/*
 * Pure unit tests: no Laravel application, no database (TD-041).
 * Dates: 2026-10-29 is a Thursday; 2026-10-30 Fri, 2026-10-31 Sat, 2026-11-01 Sun.
 * All expectations are written in local (business) time and the result is also checked to be UTC.
 */

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
    // Listed afternoon-first on purpose: the calendar must sort windows itself.
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

    it('accepts any input timezone and always returns UTC', function (string $timezone): void {
        $from = CarbonImmutable::parse('2026-10-29 17:00:00', 'Asia/Dhaka')->setTimezone($timezone);

        $result = bcCalendar()->addBusinessMinutes($from, 120);

        expect($result->tzName)->toBe('UTC')
            ->and($result->format('Y-m-d H:i:s'))->toBe('2026-11-01 04:00:00');
    })->with(['UTC', 'Asia/Dhaka', 'America/New_York', '+02:00']);

    it('uses wall-clock windows across a DST change', function (): void {
        $week = [
            'monday' => [['09:00', '17:00']],
            'tuesday' => [['09:00', '17:00']],
            'wednesday' => [['09:00', '17:00']],
            'thursday' => [['09:00', '17:00']],
            'friday' => [['09:00', '17:00']],
        ];
        $calendar = new BusinessCalendar($week, 'Europe/London');
        // Friday 16:00 BST (UTC+1); clocks go back on Sunday 2026-10-25.
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
        'every day closed' => [[
            'sunday' => [], 'monday' => [], 'tuesday' => [], 'wednesday' => [],
            'thursday' => [], 'friday' => [], 'saturday' => [],
        ], []],
    ]);
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
        ->with([0, 1, 59, 60, 61, 120, 539, 540, 541, 1440, 2880, 10000]);

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
        'capitalised weekday' => [['Sunday' => [['09:00', '18:00']]], 'Asia/Dhaka', []],
        'malformed time' => [['sunday' => [['9am', '18:00']]], 'Asia/Dhaka', []],
        'hour out of range' => [['sunday' => [['09:00', '24:00']]], 'Asia/Dhaka', []],
        'window ends before it starts' => [['sunday' => [['18:00', '09:00']]], 'Asia/Dhaka', []],
        'empty window' => [['sunday' => [['09:00', '09:00']]], 'Asia/Dhaka', []],
        'overlapping windows' => [['sunday' => [['09:00', '13:00'], ['12:00', '18:00']]], 'Asia/Dhaka', []],
        'unknown timezone' => [bcStandardWeek(), 'Mars/Olympus_Mons', []],
        'malformed holiday' => [bcStandardWeek(), 'Asia/Dhaka', ['01/11/2026']],
    ]);
});
```

- [ ] **Step 2: Run the test and confirm it fails.**
  Command: `./vendor/bin/pest tests/Unit/Sla/BusinessCalendarTest.php`
  Expected: FAIL, every case with `Error: Class "App\Services\Sla\BusinessCalendar" not found`.

- [ ] **Step 3: Implement.** Create `app/Services/Sla/BusinessCalendar.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Sla;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use RuntimeException;

/**
 * Pure business-time arithmetic (TD-041). No config, facades or now() in here: everything is injected.
 *
 * All arithmetic runs on local wall-clock time in the business timezone (local date + seconds since
 * local midnight), so a window means the same wall-clock hours on every day regardless of DST.
 * Inputs may be in any timezone; results are always UTC. Precision is one second.
 */
final class BusinessCalendar
{
    /** Consecutive days without any usable window before we decide the schedule is unusable. */
    private const MAX_LOOKAHEAD_DAYS = 366;

    private const SECONDS_PER_MINUTE = 60;

    private const SECONDS_PER_DAY = 86400;

    private const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /** @var array<string, list<array{0: int, 1: int}>> weekday => sorted [startSecond, endSecond) windows */
    private readonly array $windows;

    /** @var array<string, true> 'Y-m-d' => true */
    private readonly array $holidays;

    private readonly string $timezone;

    /**
     * @param  array<string, list<array{0:string,1:string}>>  $week  lowercase weekday => [['09:00','18:00'], ...]
     * @param  list<string>  $holidays  'Y-m-d' dates in business timezone
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

            if ($idleDays > self::MAX_LOOKAHEAD_DAYS) {
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

        // format('l') is locale-independent, unlike Carbon's translated day names.
        return $this->windows[strtolower($localDay->format('l'))] ?? [];
    }

    /**
     * Noon never falls in a DST gap or overlap, so stepping the anchor by whole days is safe;
     * only its calendar date is used.
     */
    private static function anchor(CarbonImmutable $local): CarbonImmutable
    {
        return $local->setTime(12, 0, 0);
    }

    private static function secondOfDay(CarbonImmutable $local): int
    {
        return ($local->hour * 3600) + ($local->minute * 60) + $local->second;
    }

    private static function toUtc(CarbonImmutable $day, int $secondOfDay): CarbonImmutable
    {
        return $day
            ->setTime(intdiv($secondOfDay, 3600), intdiv($secondOfDay % 3600, 60), $secondOfDay % 60)
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

        return ((int) $matches[1] * 3600) + ((int) $matches[2] * 60);
    }

    /**
     * @param  list<string>  $holidays
     * @return array<string, true>
     */
    private static function parseHolidays(array $holidays): array
    {
        $set = [];

        foreach ($holidays as $date) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                throw new InvalidArgumentException("Invalid holiday date [{$date}]; expected Y-m-d.");
            }

            $set[$date] = true;
        }

        return $set;
    }
}
```

- [ ] **Step 4: Run the test and confirm it passes.**
  Command: `./vendor/bin/pest tests/Unit/Sla/BusinessCalendarTest.php`
  Expected: PASS, 0 failures (the inverse-property test alone expands to 144 cases).

- [ ] **Step 5: Format.** Command: `./vendor/bin/pint --dirty`. Expected: exits 0 (`PASS` or `FIXED`).

- [ ] **Step 6: Static analysis.** Command: `./vendor/bin/phpstan analyse --memory-limit=1G`. Expected: `[OK] No errors`.

- [ ] **Step 7: Commit.**
  ```bash
  git add app/Services/Sla/BusinessCalendar.php tests/Unit/Sla/BusinessCalendarTest.php
  git commit -m "feat(sla): add pure window-stepping business calendar" --trailer "Refs: T02.1" --trailer "Implements: TD-041"
  ```

### 2.2 HolidayRepository (TD-045, TD-101)

- [ ] **Step 1: Write the failing test.** Create `tests/Feature/Sla/HolidayRepositoryTest.php`:

```php
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

it('returns an empty list when there are no holidays', function (): void {
    expect(app(HolidayRepository::class)->dates())->toBe([]);
});

it('caches the dates forever under sla.holidays until flushed', function (): void {
    $repository = app(HolidayRepository::class);
    Holiday::factory()->create(['date' => '2026-11-01', 'name' => 'Office closure']);

    expect($repository->dates())->toBe(['2026-11-01'])
        ->and(Cache::has('sla.holidays'))->toBeTrue();

    Holiday::factory()->create(['date' => '2026-11-05', 'name' => 'Added later']);

    expect($repository->dates())->toBe(['2026-11-01']);

    $repository->flush();

    expect(Cache::has('sla.holidays'))->toBeFalse()
        ->and($repository->dates())->toBe(['2026-11-01', '2026-11-05']);
});

it('queries the holidays table once while the cache is warm', function (): void {
    Holiday::factory()->create(['date' => '2026-11-01', 'name' => 'Office closure']);
    $holidayQueries = 0;
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$holidayQueries): void {
        if (str_contains($query->sql, 'holidays')) {
            $holidayQueries++;
        }
    });

    $repository = app(HolidayRepository::class);
    $repository->dates();
    $repository->dates();
    app(HolidayRepository::class)->dates();

    expect($holidayQueries)->toBe(1);
});
```

- [ ] **Step 2: Run the test and confirm it fails.**
  Command: `./vendor/bin/pest tests/Feature/Sla/HolidayRepositoryTest.php`
  Expected: FAIL with `Illuminate\Contracts\Container\BindingResolutionException: Target class [App\Services\Sla\HolidayRepository] does not exist.`

- [ ] **Step 3: Implement.** Create `app/Services/Sla/HolidayRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Sla;

use App\Models\Holiday;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Holidays are operational data edited by admins (TD-045), but the calendar consults them on every
 * SLA calculation, so they are cached forever and flushed explicitly on change (TD-101).
 */
final class HolidayRepository
{
    private const CACHE_KEY = 'sla.holidays';

    public function __construct(private readonly CacheRepository $cache) {}

    /** @return list<string> */
    public function dates(): array
    {
        /** @var list<string> $dates */
        $dates = $this->cache->rememberForever(self::CACHE_KEY, static fn (): array => Holiday::query()
            ->orderBy('date')
            ->get(['date'])
            ->map(static fn (Holiday $holiday): string => $holiday->date->format('Y-m-d'))
            ->values()
            ->all());

        return $dates;
    }

    public function flush(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }
}
```

- [ ] **Step 4: Run the test and confirm it passes.**
  Command: `./vendor/bin/pest tests/Feature/Sla/HolidayRepositoryTest.php`
  Expected: PASS, 4 tests, 0 failures.

- [ ] **Step 5: Format.** Command: `./vendor/bin/pint --dirty`. Expected: exits 0.

- [ ] **Step 6: Static analysis.** Command: `./vendor/bin/phpstan analyse --memory-limit=1G`. Expected: `[OK] No errors`.

- [ ] **Step 7: Commit.**
  ```bash
  git add app/Services/Sla/HolidayRepository.php tests/Feature/Sla/HolidayRepositoryTest.php
  git commit -m "feat(sla): add cached holiday repository" --trailer "Refs: T02.2" --trailer "Implements: TD-101"
  ```

### 2.3 SlaDeadlines + SlaCalculator (TD-042, TD-043, TD-045)

- [ ] **Step 1: Write the failing test.** Create `tests/Unit/Sla/SlaCalculatorTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\EscalationLevel;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Services\Sla\BusinessCalendar;
use App\Services\Sla\SlaCalculator;
use App\Services\Sla\SlaDeadlines;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/*
 * The application is booted (Eloquent casts need the connection's grammar, factories need Faker),
 * but no query may run: SlaCalculator works on unsaved tickets and never saves.
 * Dates: 2026-11-01 is a Sunday (a working day); Critical from Sun 09:00 -> warning 10:30, due 11:00.
 */
uses(TestCase::class);

beforeEach(function (): void {
    Event::listen(QueryExecuted::class, static function (QueryExecuted $query): never {
        throw new RuntimeException('SlaCalculator unit tests must not touch the database; ran: '.$query->sql);
    });
});

/**
 * @param  list<string>  $holidays
 * @param  array<string, int>|null  $minutes
 */
function slaCalculator(array $holidays = [], ?array $minutes = null, int $warningPercent = 75): SlaCalculator
{
    $day = [['09:00', '18:00']];
    $week = [
        'sunday' => $day, 'monday' => $day, 'tuesday' => $day, 'wednesday' => $day, 'thursday' => $day,
        'friday' => [], 'saturday' => [],
    ];

    return new SlaCalculator(
        new BusinessCalendar($week, 'Asia/Dhaka', $holidays),
        $minutes ?? ['low' => 2880, 'medium' => 1440, 'high' => 480, 'critical' => 120],
        $warningPercent,
    );
}

function slaAt(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Asia/Dhaka')->utc();
}

function slaLocal(?CarbonImmutable $instant): ?string
{
    return $instant?->setTimezone('Asia/Dhaka')->format('Y-m-d H:i:s');
}

/** @param array<string, mixed> $attributes */
function slaMake(array $attributes = []): Ticket
{
    // Overriding every foreign key stops the factory from create()-ing parent rows during make().
    return Ticket::factory()->make(array_merge([
        'category_id' => 1,
        'customer_id' => 1,
        'created_by_id' => 1,
        'assigned_agent_id' => null,
        'status' => TicketStatus::InProgress,
        'priority' => TicketPriority::Critical,
        'sla_paused_at' => null,
        'escalation_level' => EscalationLevel::None,
    ], $attributes));
}

/** @param array<string, mixed> $attributes */
function slaTicket(SlaCalculator $calculator, TicketPriority $priority, string $startLocal, array $attributes = []): Ticket
{
    $ticket = slaMake(array_merge(['priority' => $priority], $attributes));
    $calculator->applyInitial($ticket, slaAt($startLocal));

    return $ticket;
}

describe('construction', function (): void {
    it('rejects incomplete or invalid SLA rules', function (array $minutes, int $percent): void {
        expect(fn () => slaCalculator([], $minutes, $percent))->toThrow(InvalidArgumentException::class);
    })->with([
        'missing priority' => [['low' => 2880, 'medium' => 1440, 'high' => 480], 75],
        'zero minutes' => [['low' => 2880, 'medium' => 1440, 'high' => 480, 'critical' => 0], 75],
        'percent above 100' => [['low' => 2880, 'medium' => 1440, 'high' => 480, 'critical' => 120], 101],
        'negative percent' => [['low' => 2880, 'medium' => 1440, 'high' => 480, 'critical' => 120], -1],
    ]);
});

describe('deadlinesFor', function (): void {
    it('computes the due date and the 75% warning in business time', function (TicketPriority $priority, string $start, string $due, string $warning): void {
        $deadlines = slaCalculator()->deadlinesFor($priority, slaAt($start));

        expect($deadlines)->toBeInstanceOf(SlaDeadlines::class)
            ->and(slaLocal($deadlines->dueAt))->toBe($due)
            ->and(slaLocal($deadlines->warningAt))->toBe($warning)
            ->and($deadlines->dueAt->tzName)->toBe('UTC')
            ->and($deadlines->warningAt->tzName)->toBe('UTC');
    })->with([
        'PRD example: Critical Thu 17:00' => [TicketPriority::Critical, '2026-10-29 17:00:00', '2026-11-01 10:00:00', '2026-11-01 09:30:00'],
        'High Thu 17:00' => [TicketPriority::High, '2026-10-29 17:00:00', '2026-11-01 16:00:00', '2026-11-01 14:00:00'],
        'Medium Sun 09:00, warning on a closing boundary' => [TicketPriority::Medium, '2026-11-01 09:00:00', '2026-11-03 15:00:00', '2026-11-02 18:00:00'],
        'Low Thu 17:00 spans more than a week' => [TicketPriority::Low, '2026-10-29 17:00:00', '2026-11-08 11:00:00', '2026-11-04 17:00:00'],
    ]);

    it('floors the warning minutes', function (): void {
        $calculator = slaCalculator([], ['low' => 2880, 'medium' => 1440, 'high' => 480, 'critical' => 10], 75);

        $deadlines = $calculator->deadlinesFor(TicketPriority::Critical, slaAt('2026-11-01 09:00:00'));

        expect(slaLocal($deadlines->warningAt))->toBe('2026-11-01 09:07:00')
            ->and(slaLocal($deadlines->dueAt))->toBe('2026-11-01 09:10:00');
    });
});

describe('applyInitial', function (): void {
    it('sets the UTC start, due and warning without saving', function (): void {
        $ticket = slaMake(['priority' => TicketPriority::Critical]);

        slaCalculator()->applyInitial($ticket, CarbonImmutable::parse('2026-10-29 17:00:00', 'Asia/Dhaka'));

        expect($ticket->exists)->toBeFalse()
            ->and($ticket->sla_started_at->tzName)->toBe('UTC')
            ->and($ticket->sla_started_at->format('Y-m-d H:i:s'))->toBe('2026-10-29 11:00:00')
            ->and(slaLocal($ticket->sla_due_at))->toBe('2026-11-01 10:00:00')
            ->and(slaLocal($ticket->sla_warning_at))->toBe('2026-11-01 09:30:00')
            ->and($ticket->sla_paused_at)->toBeNull()
            ->and($ticket->escalation_level)->toBe(EscalationLevel::None);
    });
});

describe('pause and resume', function (): void {
    it('shifts due and warning by the business time left at the pause', function (string $pausedAt, string $resumedAt, string $due, string $warning): void {
        $calculator = slaCalculator();
        $ticket = slaTicket($calculator, TicketPriority::Critical, '2026-11-01 09:00:00');

        $calculator->pause($ticket, slaAt($pausedAt));
        expect(slaLocal($ticket->sla_paused_at))->toBe($pausedAt);

        $calculator->resume($ticket, slaAt($resumedAt));

        expect($ticket->sla_paused_at)->toBeNull()
            ->and(slaLocal($ticket->sla_due_at))->toBe($due)
            ->and(slaLocal($ticket->sla_warning_at))->toBe($warning)
            ->and(slaLocal($ticket->sla_started_at))->toBe('2026-11-01 09:00:00')
            ->and($ticket->exists)->toBeFalse();
    })->with([
        'paused 10:00, resumed next day 15:00' => ['2026-11-01 10:00:00', '2026-11-02 15:00:00', '2026-11-02 16:00:00', '2026-11-02 15:30:00'],
        'resumed late Thursday, carries over the weekend' => ['2026-11-01 10:00:00', '2026-11-05 17:30:00', '2026-11-08 09:30:00', '2026-11-05 18:00:00'],
        'resumed on a Friday, counts from the next opening' => ['2026-11-01 10:00:00', '2026-11-06 12:00:00', '2026-11-08 10:00:00', '2026-11-08 09:30:00'],
        'paused and resumed at the same instant' => ['2026-11-01 10:00:00', '2026-11-01 10:00:00', '2026-11-01 11:00:00', '2026-11-01 10:30:00'],
        'paused between warning and due: warning floored to zero' => ['2026-11-01 10:45:00', '2026-11-02 10:00:00', '2026-11-02 10:15:00', '2026-11-02 10:00:00'],
    ]);

    it('keeps the original pause moment when paused twice', function (): void {
        $calculator = slaCalculator();
        $ticket = slaTicket($calculator, TicketPriority::Critical, '2026-11-01 09:00:00');

        $calculator->pause($ticket, slaAt('2026-11-01 10:00:00'));
        $calculator->pause($ticket, slaAt('2026-11-01 10:20:00'));

        expect(slaLocal($ticket->sla_paused_at))->toBe('2026-11-01 10:00:00');
    });

    it('does nothing when resuming an unpaused ticket', function (): void {
        $calculator = slaCalculator();
        $ticket = slaTicket($calculator, TicketPriority::Critical, '2026-11-01 09:00:00');

        $calculator->resume($ticket, slaAt('2026-11-02 15:00:00'));

        expect(slaLocal($ticket->sla_due_at))->toBe('2026-11-01 11:00:00')
            ->and(slaLocal($ticket->sla_warning_at))->toBe('2026-11-01 10:30:00');
    });

    it('resumes a ticket paused after its deadline as still breached (remaining 0)', function (): void {
        $calculator = slaCalculator();
        $ticket = slaTicket($calculator, TicketPriority::Critical, '2026-11-01 09:00:00', [
            'escalation_level' => EscalationLevel::Breached,
        ]);

        $calculator->pause($ticket, slaAt('2026-11-01 12:00:00'));
        $calculator->resume($ticket, slaAt('2026-11-02 10:00:00'));

        expect(slaLocal($ticket->sla_due_at))->toBe('2026-11-02 10:00:00')
            ->and(slaLocal($ticket->sla_warning_at))->toBe('2026-11-02 10:00:00')
            ->and($ticket->escalation_level)->toBe(EscalationLevel::Breached)
            ->and($calculator->levelFor($ticket, slaAt('2026-11-02 10:00:00')))->toBe(EscalationLevel::Breached);
    });

    it('lowers a stale escalation level when the resume moves the deadline later', function (): void {
        $calculator = slaCalculator();
        $ticket = slaTicket($calculator, TicketPriority::Critical, '2026-11-01 09:00:00', [
            'escalation_level' => EscalationLevel::Breached,
        ]);

        $calculator->pause($ticket, slaAt('2026-11-01 10:00:00'));
        $calculator->resume($ticket, slaAt('2026-11-02 09:00:00'));

        expect(slaLocal($ticket->sla_due_at))->toBe('2026-11-02 10:00:00')
            ->and($ticket->escalation_level)->toBe(EscalationLevel::None);
    });
});

describe('recalculateForPriority', function (): void {
    it('measures the new deadline from sla_started_at, not from now', function (): void {
        $calculator = slaCalculator();
        $ticket = slaTicket($calculator, TicketPriority::Medium, '2026-11-01 09:00:00');

        $ticket->priority = TicketPriority::High;
        $calculator->recalculateForPriority($ticket, slaAt('2026-11-02 10:00:00'));

        expect(slaLocal($ticket->sla_started_at))->toBe('2026-11-01 09:00:00')
            ->and(slaLocal($ticket->sla_due_at))->toBe('2026-11-01 17:00:00')
            ->and(slaLocal($ticket->sla_warning_at))->toBe('2026-11-01 15:00:00')
            ->and($ticket->exists)->toBeFalse();
    });

    it('recalculates from the start while paused and keeps the pause', function (): void {
        $calculator = slaCalculator();
        $ticket = slaTicket($calculator, TicketPriority::Low, '2026-11-01 09:00:00');
        $calculator->pause($ticket, slaAt('2026-11-01 10:00:00'));

        $ticket->priority = TicketPriority::Critical;
        $calculator->recalculateForPriority($ticket, slaAt('2026-11-02 09:00:00'));

        expect(slaLocal($ticket->sla_due_at))->toBe('2026-11-01 11:00:00')
            ->and(slaLocal($ticket->sla_warning_at))->toBe('2026-11-01 10:30:00')
            ->and(slaLocal($ticket->sla_paused_at))->toBe('2026-11-01 10:00:00')
            ->and($ticket->escalation_level)->toBe(EscalationLevel::None);

        $calculator->resume($ticket, slaAt('2026-11-02 12:00:00'));

        expect(slaLocal($ticket->sla_due_at))->toBe('2026-11-02 13:00:00')
            ->and(slaLocal($ticket->sla_warning_at))->toBe('2026-11-02 12:30:00');
    });

    it('lowers escalation_level when the deadline moves later and never raises it', function (TicketPriority $from, EscalationLevel $level, string $now, TicketPriority $to, EscalationLevel $expected): void {
        $calculator = slaCalculator();
        $ticket = slaTicket($calculator, $from, '2026-11-01 09:00:00', ['escalation_level' => $level]);

        $ticket->priority = $to;
        $calculator->recalculateForPriority($ticket, slaAt($now));

        expect($ticket->escalation_level)->toBe($expected);
    })->with([
        'approaching High -> Low: back to none' => [TicketPriority::High, EscalationLevel::Approaching, '2026-11-01 15:30:00', TicketPriority::Low, EscalationLevel::None],
        'breached Critical -> High, now approaching' => [TicketPriority::Critical, EscalationLevel::Breached, '2026-11-01 15:30:00', TicketPriority::High, EscalationLevel::Approaching],
        'breached Critical -> High, now within' => [TicketPriority::Critical, EscalationLevel::Breached, '2026-11-01 13:00:00', TicketPriority::High, EscalationLevel::None],
        'breached Critical -> High, still breached' => [TicketPriority::Critical, EscalationLevel::Breached, '2026-11-02 10:00:00', TicketPriority::High, EscalationLevel::Breached],
        'within Medium -> High, already past due: not raised (sla:scan escalates)' => [TicketPriority::Medium, EscalationLevel::None, '2026-11-02 10:00:00', TicketPriority::High, EscalationLevel::None],
    ]);
});

describe('levelFor', function (): void {
    it('reflects the SLA clock, frozen while paused', function (?string $pausedAt, string $now, EscalationLevel $expected): void {
        $calculator = slaCalculator();
        $ticket = slaTicket($calculator, TicketPriority::Critical, '2026-11-01 09:00:00');

        if ($pausedAt !== null) {
            $calculator->pause($ticket, slaAt($pausedAt));
        }

        expect($calculator->levelFor($ticket, slaAt($now)))->toBe($expected)
            ->and($ticket->escalation_level)->toBe(EscalationLevel::None);
    })->with([
        'before warning' => [null, '2026-11-01 10:29:00', EscalationLevel::None],
        'warning reached exactly' => [null, '2026-11-01 10:30:00', EscalationLevel::Approaching],
        'between warning and due' => [null, '2026-11-01 10:59:59', EscalationLevel::Approaching],
        'due reached exactly' => [null, '2026-11-01 11:00:00', EscalationLevel::Breached],
        'long after due' => [null, '2026-11-09 09:00:00', EscalationLevel::Breached],
        'paused before warning: clock frozen' => ['2026-11-01 10:00:00', '2026-11-09 09:00:00', EscalationLevel::None],
        'paused while approaching' => ['2026-11-01 10:45:00', '2026-11-09 09:00:00', EscalationLevel::Approaching],
        'paused after the breach' => ['2026-11-01 11:30:00', '2026-11-09 09:00:00', EscalationLevel::Breached],
    ]);
});
```

- [ ] **Step 2: Run the test and confirm it fails.**
  Command: `./vendor/bin/pest tests/Unit/Sla/SlaCalculatorTest.php`
  Expected: FAIL, every case with `Error: Class "App\Services\Sla\SlaCalculator" not found`. If instead a case fails with `SlaCalculator unit tests must not touch the database`, T01's `TicketFactory` runs queries during `make()`: stop and report to the controller (do not edit the factory).

- [ ] **Step 3: Implement the value object.** Create `app/Services/Sla/SlaDeadlines.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Sla;

use Carbon\CarbonImmutable;

final readonly class SlaDeadlines
{
    public function __construct(
        public CarbonImmutable $dueAt,
        public CarbonImmutable $warningAt,
    ) {}
}
```

- [ ] **Step 4: Implement the calculator.** Create `app/Services/Sla/SlaCalculator.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Sla;

use App\Enums\EscalationLevel;
use App\Enums\TicketPriority;
use App\Models\Ticket;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The only reader of the SLA rules (TD-045) and the only writer of sla_* columns besides sla:scan.
 * Never saves: the calling Action owns persistence and the transaction (TD-001, TD-053).
 */
final class SlaCalculator
{
    /** @var array<string, int> */
    private readonly array $resolutionMinutes;

    /** @param array<string,int> $resolutionMinutes keyed by TicketPriority value */
    public function __construct(
        private readonly BusinessCalendar $calendar,
        array $resolutionMinutes,
        private readonly int $warningPercent,
    ) {
        foreach (TicketPriority::cases() as $priority) {
            $minutes = $resolutionMinutes[$priority->value] ?? null;

            if (! is_int($minutes) || $minutes <= 0) {
                throw new InvalidArgumentException("SLA resolution minutes for priority [{$priority->value}] must be a positive integer.");
            }
        }

        if ($warningPercent < 0 || $warningPercent > 100) {
            throw new InvalidArgumentException('SLA warning threshold must be between 0 and 100 percent.');
        }

        $this->resolutionMinutes = $resolutionMinutes;
    }

    public function deadlinesFor(TicketPriority $priority, CarbonImmutable $start): SlaDeadlines
    {
        $minutes = $this->resolutionMinutes[$priority->value];
        $warningMinutes = intdiv($minutes * $this->warningPercent, 100);

        return new SlaDeadlines(
            dueAt: $this->calendar->addBusinessMinutes($start, $minutes),
            warningAt: $this->calendar->addBusinessMinutes($start, $warningMinutes),
        );
    }

    public function applyInitial(Ticket $ticket, CarbonImmutable $start): void
    {
        // Eloquent formats dates in their own timezone, so convert before assigning.
        $start = $start->utc()->startOfSecond();
        $deadlines = $this->deadlinesFor($ticket->priority, $start);

        $ticket->sla_started_at = $start;
        $ticket->sla_due_at = $deadlines->dueAt;
        $ticket->sla_warning_at = $deadlines->warningAt;
    }

    public function recalculateForPriority(Ticket $ticket, CarbonImmutable $now): void
    {
        // From the original start, so re-prioritising can't quietly extend the deadline (TD-043).
        $deadlines = $this->deadlinesFor($ticket->priority, $ticket->sla_started_at);

        $ticket->sla_due_at = $deadlines->dueAt;
        $ticket->sla_warning_at = $deadlines->warningAt;

        // sla_paused_at is kept: resume() will credit the ongoing pause against these new deadlines.
        $this->lowerEscalation($ticket, $now);
    }

    public function pause(Ticket $ticket, CarbonImmutable $now): void
    {
        if ($ticket->isPaused()) {
            return;
        }

        $ticket->sla_paused_at = $now->utc()->startOfSecond();
    }

    public function resume(Ticket $ticket, CarbonImmutable $now): void
    {
        $pausedAt = $ticket->sla_paused_at;

        if ($pausedAt === null) {
            return;
        }

        $now = $now->utc()->startOfSecond();
        // businessMinutesBetween floors at zero, so a threshold already passed at the pause lands on "now".
        $remainingToDue = $this->calendar->businessMinutesBetween($pausedAt, $ticket->sla_due_at);
        $remainingToWarning = $this->calendar->businessMinutesBetween($pausedAt, $ticket->sla_warning_at);

        $ticket->sla_due_at = $this->calendar->addBusinessMinutes($now, $remainingToDue);
        $ticket->sla_warning_at = $this->calendar->addBusinessMinutes($now, $remainingToWarning);
        $ticket->sla_paused_at = null;

        $this->lowerEscalation($ticket, $now);
    }

    public function levelFor(Ticket $ticket, CarbonImmutable $now): EscalationLevel
    {
        // While paused the SLA clock is frozen at the moment of the pause.
        $at = $ticket->sla_paused_at ?? $now;

        if ($at->greaterThanOrEqualTo($ticket->sla_due_at)) {
            return EscalationLevel::Breached;
        }

        if ($at->greaterThanOrEqualTo($ticket->sla_warning_at)) {
            return EscalationLevel::Approaching;
        }

        return EscalationLevel::None;
    }

    /**
     * Only ever lowers the level. Raising it here would mark the ticket escalated without
     * sla:scan dispatching TicketSlaApproaching/TicketSlaBreached, so nobody would be notified.
     */
    private function lowerEscalation(Ticket $ticket, CarbonImmutable $now): void
    {
        $current = $ticket->getAttribute('escalation_level');
        $current = $current instanceof EscalationLevel ? $current : EscalationLevel::None;
        $target = $this->levelFor($ticket, $now);

        if ($target->value < $current->value) {
            $ticket->escalation_level = $target;
        }
    }
}
```

- [ ] **Step 5: Run the test and confirm it passes.**
  Command: `./vendor/bin/pest tests/Unit/Sla/SlaCalculatorTest.php`
  Expected: PASS, 0 failures.

- [ ] **Step 6: Run both unit files together to catch helper-name collisions.**
  Command: `./vendor/bin/pest tests/Unit/Sla`
  Expected: PASS, 0 failures.

- [ ] **Step 7: Format.** Command: `./vendor/bin/pint --dirty`. Expected: exits 0.

- [ ] **Step 8: Static analysis.** Command: `./vendor/bin/phpstan analyse --memory-limit=1G`. Expected: `[OK] No errors`.

- [ ] **Step 9: Commit.**
  ```bash
  git add app/Services/Sla/SlaDeadlines.php app/Services/Sla/SlaCalculator.php tests/Unit/Sla/SlaCalculatorTest.php
  git commit -m "feat(sla): add SLA calculator with pause, resume and recalculation" --trailer "Refs: T02.3" --trailer "Implements: TD-042, TD-043"
  ```

### 2.4 Config files + SlaServiceProvider wiring (TD-045, TD-130)

- [ ] **Step 1: Write the failing test.** Create `tests/Feature/Sla/SlaServiceProviderTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\TicketPriority;
use App\Models\Holiday;
use App\Providers\SlaServiceProvider;
use App\Services\Sla\BusinessCalendar;
use App\Services\Sla\HolidayRepository;
use App\Services\Sla\SlaCalculator;
use Carbon\CarbonImmutable;

it('is registered in bootstrap/providers.php', function (): void {
    expect(app()->getProviders(SlaServiceProvider::class))->not->toBeEmpty();
});

it('ships the default SLA rules', function (): void {
    expect(config('sla.resolution_minutes'))->toBe([
        'low' => 2880,
        'medium' => 1440,
        'high' => 480,
        'critical' => 120,
    ])->and(config('sla.warning_threshold_percent'))->toBe(75);
});

it('ships the default business hours', function (): void {
    $day = [['09:00', '18:00']];

    expect(config('business_hours.timezone'))->toBe('Asia/Dhaka')
        ->and(config('business_hours.week'))->toBe([
            'sunday' => $day,
            'monday' => $day,
            'tuesday' => $day,
            'wednesday' => $day,
            'thursday' => $day,
            'friday' => [],
            'saturday' => [],
        ]);
});

it('resolves a calculator that reproduces the PRD example from config', function (): void {
    $deadlines = app(SlaCalculator::class)->deadlinesFor(
        TicketPriority::Critical,
        CarbonImmutable::parse('2026-10-29 17:00:00', 'Asia/Dhaka'),
    );

    expect($deadlines->dueAt->format('Y-m-d H:i:s'))->toBe('2026-11-01 04:00:00')
        ->and($deadlines->dueAt->setTimezone('Asia/Dhaka')->format('l H:i'))->toBe('Sunday 10:00')
        ->and($deadlines->warningAt->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-11-01 09:30');
});

it('builds the calendar with holidays from the database', function (): void {
    Holiday::factory()->create(['date' => '2026-11-01', 'name' => 'Office closure']);
    app(HolidayRepository::class)->flush();
    app()->forgetScopedInstances();

    $deadlines = app(SlaCalculator::class)->deadlinesFor(
        TicketPriority::Critical,
        CarbonImmutable::parse('2026-10-29 17:00:00', 'Asia/Dhaka'),
    );

    expect($deadlines->dueAt->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-11-02 10:00')
        ->and(app(BusinessCalendar::class)->isBusinessTime(CarbonImmutable::parse('2026-11-01 10:00:00', 'Asia/Dhaka')))->toBeFalse();
});

it('binds the calendar and the calculator as scoped instances', function (): void {
    $calendar = app(BusinessCalendar::class);
    $calculator = app(SlaCalculator::class);

    expect(app(BusinessCalendar::class))->toBe($calendar)
        ->and(app(SlaCalculator::class))->toBe($calculator);

    app()->forgetScopedInstances();

    expect(app(BusinessCalendar::class))->not->toBe($calendar)
        ->and(app(SlaCalculator::class))->not->toBe($calculator);
});

it('reads changed SLA rules on the next resolution', function (): void {
    config(['sla.resolution_minutes.critical' => 60, 'sla.warning_threshold_percent' => 50]);
    app()->forgetScopedInstances();

    $deadlines = app(SlaCalculator::class)->deadlinesFor(
        TicketPriority::Critical,
        CarbonImmutable::parse('2026-11-01 09:00:00', 'Asia/Dhaka'),
    );

    expect($deadlines->dueAt->setTimezone('Asia/Dhaka')->format('H:i'))->toBe('10:00')
        ->and($deadlines->warningAt->setTimezone('Asia/Dhaka')->format('H:i'))->toBe('09:30');
});
```

- [ ] **Step 2: Run the test and confirm it fails.**
  Command: `./vendor/bin/pest tests/Feature/Sla/SlaServiceProviderTest.php`
  Expected: FAIL. The first test fails with `Failed asserting that an array is not empty.` (the provider class doesn't exist yet), and the config tests fail with `Failed asserting that null is identical to Array (...)`.

- [ ] **Step 3: Create the SLA config.** Create `config/sla.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\TicketPriority;

/*
 * Resolution SLA rules (TD-045). Read only by App\Services\Sla\SlaCalculator.
 * Existing tickets keep their stored deadlines; a change applies to new or recalculated tickets.
 */
return [
    // Business minutes per priority, keyed by TicketPriority value.
    'resolution_minutes' => [
        TicketPriority::Low->value => (int) env('SLA_MINUTES_LOW', 2880),
        TicketPriority::Medium->value => (int) env('SLA_MINUTES_MEDIUM', 1440),
        TicketPriority::High->value => (int) env('SLA_MINUTES_HIGH', 480),
        TicketPriority::Critical->value => (int) env('SLA_MINUTES_CRITICAL', 120),
    ],

    // "Approaching" starts once this share of the business time has elapsed.
    'warning_threshold_percent' => (int) env('SLA_WARNING_THRESHOLD_PERCENT', 75),
];
```

- [ ] **Step 4: Create the business-hours config.** Create `config/business_hours.php`:

```php
<?php

declare(strict_types=1);

/*
 * Company-wide business calendar (TD-041). Windows are local wall-clock times in `timezone`,
 * 'HH:MM' from 00:00 to 23:59, end exclusive. Several windows per day are allowed (e.g. a lunch
 * break). An empty list closes the day. Holidays live in the `holidays` table.
 */
return [
    'timezone' => env('BUSINESS_TIMEZONE', 'Asia/Dhaka'),

    'week' => [
        'sunday' => [['09:00', '18:00']],
        'monday' => [['09:00', '18:00']],
        'tuesday' => [['09:00', '18:00']],
        'wednesday' => [['09:00', '18:00']],
        'thursday' => [['09:00', '18:00']],
        'friday' => [],
        'saturday' => [],
    ],
];
```

- [ ] **Step 5: Create the provider.** Create `app/Providers/SlaServiceProvider.php`:

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Sla\BusinessCalendar;
use App\Services\Sla\HolidayRepository;
use App\Services\Sla\SlaCalculator;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Builds the pure calendar from config + cached holidays. Both bindings are scoped, so a
 * long-running queue worker picks up holiday changes on the next job instead of keeping a stale calendar.
 */
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
```

- [ ] **Step 6: Register the provider.** In `bootstrap/providers.php`, add `App\Providers\SlaServiceProvider::class,` as the last element of the returned array. Keep every entry T00 put there. With T00's baseline the file reads:

```php
<?php

declare(strict_types=1);

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\SlaServiceProvider::class,
];
```

- [ ] **Step 7: Run the test and confirm it passes.**
  Command: `./vendor/bin/pest tests/Feature/Sla/SlaServiceProviderTest.php`
  Expected: PASS, 7 tests, 0 failures.

- [ ] **Step 8: Run everything this task added.**
  Command: `./vendor/bin/pest tests/Unit/Sla tests/Feature/Sla`
  Expected: PASS, 0 failures.

- [ ] **Step 9: Format.** Command: `./vendor/bin/pint --dirty`. Expected: exits 0.

- [ ] **Step 10: Static analysis.** Command: `./vendor/bin/phpstan analyse --memory-limit=1G`. Expected: `[OK] No errors`.

- [ ] **Step 11: Commit.**
  ```bash
  git add config/sla.php config/business_hours.php app/Providers/SlaServiceProvider.php bootstrap/providers.php tests/Feature/Sla/SlaServiceProviderTest.php
  git commit -m "feat(sla): wire SLA config and service provider" --trailer "Refs: T02.4" --trailer "Implements: TD-045, TD-130"
  ```

## 6. Acceptance criteria

- [ ] `BusinessCalendar` has exactly the public API in README §3.4. It reads no config, uses no facades and never calls `now()` (grep `app/Services/Sla/BusinessCalendar.php` for `config(`, `now(`, `Facades`: no hits).
- [ ] The PRD example passes: Critical, Thursday 17:00 Asia/Dhaka → Sunday 10:00 Asia/Dhaka (`2026-11-01 04:00:00` UTC), both in the unit test and through the container with the shipped config.
- [ ] Unit datasets cover: before opening, after closing, Friday and Saturday starts, a holiday on the next working day, 17:59 + 1 → 18:00, an 18:00 start, a 48-hour Low ticket spanning more than a week, a lunch-break schedule, zero minutes, the empty-schedule safety cap, the inverse property, UTC in → UTC out, and a DST change.
- [ ] `SlaCalculator` covers: 75 % warning with flooring, pause/resume shifting by the remaining business time, resume after the deadline (remaining 0), recalculation from `sla_started_at` including while paused, `levelFor` (frozen while paused), and escalation lowered (never raised) when the deadline moves.
- [ ] `SlaCalculatorTest` runs no SQL (enforced by the `QueryExecuted` guard), and no `SlaCalculator` method saves the ticket.
- [ ] `HolidayRepository` caches under `sla.holidays` with `rememberForever`, and `flush()` forgets it.
- [ ] `config/sla.php` and `config/business_hours.php` hold every SLA and hours value. No minutes, percentages, hours or timezone are hard-coded in `app/`.
- [ ] `SlaServiceProvider` is listed in `bootstrap/providers.php` and binds `BusinessCalendar` and `SlaCalculator` as scoped.
- [ ] Every new PHP file has `declare(strict_types=1);`, and every class is `final`.

## 7. Verification

Run the gate in the worktree:

```bash
./vendor/bin/pint --test && ./vendor/bin/phpstan analyse --memory-limit=1G && ./vendor/bin/pest --parallel
```

Expected: Pint `PASS`, PHPStan `[OK] No errors`, Pest all green with 0 failures (including every test from T00 and T01). No `resources/js` changes, so the frontend gate is not needed.

## Contract notes for controller

1. **Escalation is only ever lowered by the calculator.** README §3.4 says `recalculateForPriority` and `resume` "reset escalation_level via levelFor". This task reads that as `min(current, levelFor(now))`. Raising the level there would make `sla:scan` skip the `TicketSlaApproaching`/`TicketSlaBreached` events, so nobody would be notified. Please confirm, and mention it in T05 (`ChangeTicketPriority`) and T09.
2. **`levelFor` on a paused ticket** evaluates at `sla_paused_at` (the clock is frozen). The contract leaves this unspecified.
3. **Pause credit is lost on a priority change.** TD-043 recomputes from `sla_started_at`, so business time credited by an *earlier, completed* pause/resume cycle is dropped when the priority changes later. An ongoing pause is preserved. Fixing this would need an accumulated `sla_paused_minutes` column (a schema change owned by T01) or the old priority's minutes. This task implements TD-043 literally; consider recording the trade-off in TD-043.
4. **Resume after the deadline** follows the spec formula `due = add(now, 0) = now`, so the stored `sla_due_at` moves from the original deadline to the resume time (still breached). If the dashboard should show "breached since the original deadline", the spec needs a change.
5. **Unit-test bootstrap assumption.** `tests/Unit/Sla/SlaCalculatorTest.php` declares `uses(Tests\TestCase::class)`. This assumes T01's `tests/Pest.php` applies `TestCase`, `RefreshDatabase` and the role-seeding `beforeEach` to `Feature` only. If it also targets `Unit`, the file's `uses()` call conflicts and the seeding would trip the no-SQL guard. It also assumes `TicketFactory::make()` runs no queries once the foreign keys are overridden.
6. **`HolidayRepository::flush()` callers.** T13's holiday Actions must call `flush()` after every create, update or delete. Already-resolved scoped calendars refresh on the next request or job.
7. **Env keys** `BUSINESS_TIMEZONE`, `SLA_MINUTES_{LOW,MEDIUM,HIGH,CRITICAL}` and `SLA_WARNING_THRESHOLD_PERCENT` should be documented in `.env.example`. T02 may not touch that file, so this falls to T00 or T14.
