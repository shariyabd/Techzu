# T03 — Ticket number generator

**Objective:** Allocate unique, human-readable ticket numbers (`TKT-{YYYY}-{%06d}`) from a per-year counter row, so concurrent creations are serialized by a database row lock and a rolled-back creation also rolls back the counter.

- **Depends on:** T01 (`ticket_sequences` table, `tests/Pest.php` with `concurrentConnection()` and the `tests/Concurrency` DatabaseTruncation suite)
- **Wave:** 2 (parallel with T02, T04)
- **TDs implemented:** TD-022 (per-year counter row), TD-004 (MySQL row locks), TD-032 (two-connection concurrency test), TD-053 (nested transaction inside the caller's), TD-130 (`config/tickets.php`), TD-041 (year taken in the business timezone)

---

## Files

**Create**
- `config/tickets.php`
- `app/Services/Tickets/TicketNumberGenerator.php`
- `tests/Feature/Services/Tickets/TicketNumberGeneratorTest.php`
- `tests/Concurrency/TicketNumberConcurrencyTest.php`

**Modify**
- none

**Must not touch**
- `config/business_hours.php` (T02 owns it; this task only *reads* `business_hours.timezone`)
- every migration (T01), `tests/Pest.php` (T01), `bootstrap/app.php`, `routes/**`, `app/Providers/**`
- `.env.example` (T00 already ships `TICKET_NUMBER_PREFIX=TKT`)

---

## Interfaces

**Consumes**
- Table `ticket_sequences(year smallint unsigned PK, last_number int unsigned, timestamps)` — T01.
- Config key `business_hours.timezone` (string, default `Asia/Dhaka`) — created by T02 in the same wave. At runtime both exist before anything calls the generator (T05 depends on T02 and T03). This task's tests set the key explicitly, so T03 never depends on T02's file.
- `concurrentConnection(): Illuminate\Database\Connection` — helper in `tests/Pest.php` (T01). It clones the default connection's config (including the per-process `--parallel` database name) into `database.connections.mysql_concurrent` and returns a fresh connection.
- `tests/Concurrency/*` runs with `DatabaseTruncation` (T01 `tests/Pest.php`), so data committed on one connection is visible to the other.

**Produces** (README §3.4, exact)
```php
// app/Services/Tickets/TicketNumberGenerator.php
final class TicketNumberGenerator
{
    public function __construct(ConnectionInterface $connection, \Illuminate\Config\Repository $config);
    public function next(CarbonImmutable $at): string; // opens its own (nested) transaction
}
```
```php
// config/tickets.php
return ['number_prefix' => env('TICKET_NUMBER_PREFIX', 'TKT')];
```
The constructor is autowired (`ConnectionInterface` resolves to the default connection through the `db.connection` alias). Callers use `app(TicketNumberGenerator::class)` or constructor injection; tests may construct it with a second connection.

Behavior contract for callers (T05 `CreateTicket`, T11 `ImportTicketRow`):
- Call `next()` **inside** the caller's `DB::transaction()`. The generator's own transaction then becomes a savepoint, the row lock is held until the caller commits, and a caller rollback also rolls back the counter (TD-022).
- `$at` is the ticket's SLA start (now for new tickets, the CSV `created_at` for imports). The year is `$at` converted to `config('business_hours.timezone')`.
- If `business_hours.timezone` is missing or not a string, `next()` throws `InvalidArgumentException` instead of silently picking a year in UTC.

---

## Subtasks & mini-waves

| Subtask | Files | Depends on | Parallel-safe with |
|---|---|---|---|
| 3.1 Generator + config + behavior tests | `config/tickets.php`, `app/Services/Tickets/TicketNumberGenerator.php`, `tests/Feature/Services/Tickets/TicketNumberGeneratorTest.php` | T01 merged | — |
| 3.2 Two-connection lock proof | `tests/Concurrency/TicketNumberConcurrencyTest.php` | 3.1 | — |

Mini-waves: **A** = 3.1 → **B** = 3.2. Run by a single implementer, in order.

### Why an upsert instead of `INSERT IGNORE` + `SELECT … FOR UPDATE`

Spec §5 and TD-022 sketch three statements: `INSERT IGNORE` the year row, `SELECT … FOR UPDATE`, `UPDATE`. In InnoDB, an `INSERT` (including `INSERT IGNORE`) that hits a duplicate key takes a **shared** lock on the existing record. Two concurrent creations both get that shared lock, then both ask to upgrade to an **exclusive** lock for `FOR UPDATE`. Each waits for the other, and InnoDB kills one with a deadlock (error 1213). That would happen on ordinary concurrent ticket creation, not only at year rollover.

`INSERT … ON DUPLICATE KEY UPDATE last_number = last_number + 1` takes the **exclusive** lock straight away, whether it inserts or updates, so there's no shared-to-exclusive upgrade and no deadlock. The follow-up `SELECT … FOR UPDATE` reads the value this transaction just wrote. That read doesn't wait, because the transaction already holds the lock, and it doesn't read from the REPEATABLE READ snapshot. The guarantees TD-022 asks for still hold: one row per year, serialization by row lock, a rollback undoes the increment, and `tickets.number` stays unique as the final guard. This divergence from the spec's pseudo-code was approved; TD-022 and spec §5 are being amended (see Contract notes).

The statement is raw SQL through `affectingStatement()`, not `Query\Builder::upsert()`. So Laravel 13's new check, which makes `upsert()` throw `InvalidArgumentException` when `uniqueBy` is empty on MySQL/MariaDB, doesn't apply here. MySQL detects the duplicate through the `year` primary key either way. Keep it raw: the increment has to read the row's current value (`last_number = last_number + 1`) under the lock the insert takes.

---

## Subtask 3.1 — Generator, config and behavior tests

- [ ] **Step 1: Write the failing feature test** `tests/Feature/Services/Tickets/TicketNumberGeneratorTest.php`

```php
<?php

declare(strict_types=1);

use App\Services\Tickets\TicketNumberGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// T02 owns config/business_hours.php; setting the key here keeps this suite independent of it.
beforeEach(function (): void {
    config(['business_hours.timezone' => 'Asia/Dhaka']);
});

function ticketNumbers(): TicketNumberGenerator
{
    return app(TicketNumberGenerator::class);
}

function utc(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'UTC');
}

function sequenceValue(int $year): ?int
{
    $value = DB::table('ticket_sequences')->where('year', $year)->value('last_number');

    return $value === null ? null : (int) $value;
}

it('ships TKT as the default prefix', function (): void {
    expect(config('tickets.number_prefix'))->toBe('TKT');
});

it('formats the first number of a year with the prefix and six-digit padding', function (): void {
    $number = ticketNumbers()->next(utc('2026-03-10 08:00:00'));

    expect($number)->toBe('TKT-2026-000001')
        ->and($number)->toMatch('/^TKT-\d{4}-\d{6,}$/');
});

it('uses the configured prefix', function (): void {
    config(['tickets.number_prefix' => 'SUP']);

    expect(ticketNumbers()->next(utc('2026-03-10 08:00:00')))->toBe('SUP-2026-000001');
});

it('increments sequentially within a year', function (): void {
    $generator = ticketNumbers();
    $at = utc('2026-05-01 04:00:00');

    expect([$generator->next($at), $generator->next($at), $generator->next($at)])
        ->toBe(['TKT-2026-000001', 'TKT-2026-000002', 'TKT-2026-000003'])
        ->and(sequenceValue(2026))->toBe(3);
});

it('takes the year from the business timezone, not UTC', function (string $utcTime, string $expected): void {
    expect(ticketNumbers()->next(utc($utcTime)))->toBe($expected);
})->with([
    '2026-12-31 17:59:59 UTC is 23:59:59 in Dhaka (2026)' => ['2026-12-31 17:59:59', 'TKT-2026-000001'],
    '2026-12-31 18:00:00 UTC is midnight in Dhaka (2027)' => ['2026-12-31 18:00:00', 'TKT-2027-000001'],
    '2026-12-31 20:00:00 UTC is 02:00 in Dhaka (2027)' => ['2026-12-31 20:00:00', 'TKT-2027-000001'],
]);

it('restarts the counter for a new year and keeps the previous year intact', function (): void {
    $generator = ticketNumbers();

    $numbers = [
        $generator->next(utc('2026-12-31 17:00:00')),
        $generator->next(utc('2026-12-31 17:30:00')),
        $generator->next(utc('2026-12-31 20:00:00')),
        $generator->next(utc('2026-06-01 06:00:00')),
    ];

    expect($numbers)->toBe(['TKT-2026-000001', 'TKT-2026-000002', 'TKT-2027-000001', 'TKT-2026-000003'])
        ->and(sequenceValue(2026))->toBe(3)
        ->and(sequenceValue(2027))->toBe(1);
});

it('grows past six digits instead of wrapping', function (): void {
    DB::table('ticket_sequences')->insert([
        'year' => 2026,
        'last_number' => 999998,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $generator = ticketNumbers();
    $at = utc('2026-07-07 07:00:00');

    expect($generator->next($at))->toBe('TKT-2026-999999')
        ->and($generator->next($at))->toBe('TKT-2026-1000000')
        ->and(sequenceValue(2026))->toBe(1000000);
});

it('rolls the counter back together with the surrounding transaction', function (): void {
    $generator = ticketNumbers();
    $at = utc('2026-08-08 08:00:00');

    try {
        DB::transaction(function () use ($generator, $at): void {
            expect($generator->next($at))->toBe('TKT-2026-000001');

            throw new RuntimeException('ticket insert failed');
        });
    } catch (RuntimeException) {
        // The caller's failure is the point of this test.
    }

    expect(sequenceValue(2026))->toBeNull()
        ->and($generator->next($at))->toBe('TKT-2026-000001');
});

it('fails loudly when the business timezone is not configured', function (): void {
    config(['business_hours.timezone' => null]);

    ticketNumbers()->next(utc('2026-01-01 00:00:00'));
})->throws(InvalidArgumentException::class);
```

- [ ] **Step 2: Run the test and confirm it fails**

```bash
./vendor/bin/pest tests/Feature/Services/Tickets/TicketNumberGeneratorTest.php
```
Expected: **FAIL**. The first test fails with `Failed asserting that null is identical to 'TKT'` (no `config/tickets.php` yet), and the rest fail with `Target class [App\Services\Tickets\TicketNumberGenerator] does not exist.`

- [ ] **Step 3: Create `config/tickets.php`**

```php
<?php

declare(strict_types=1);

return [
    // Prefix of the public ticket number (TKT-2026-000001). A change applies to new tickets only;
    // existing numbers are stored, never recomputed.
    'number_prefix' => env('TICKET_NUMBER_PREFIX', 'TKT'),
];
```

- [ ] **Step 4: Create `app/Services/Tickets/TicketNumberGenerator.php`**

```php
<?php

declare(strict_types=1);

namespace App\Services\Tickets;

use Carbon\CarbonImmutable;
use Illuminate\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;

final class TicketNumberGenerator
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly Config $config,
    ) {}

    public function next(CarbonImmutable $at): string
    {
        // Typed getters throw on a missing key: a silent UTC fallback would number
        // tickets created on Dhaka's New Year's morning with the previous year.
        $year = $at->setTimezone($this->config->string('business_hours.timezone'))->year;
        $prefix = $this->config->string('tickets.number_prefix');

        $number = $this->connection->transaction(function () use ($year): int {
            $now = CarbonImmutable::now();

            // The upsert takes the row's exclusive lock immediately. INSERT IGNORE would take a
            // shared lock on the existing row first, and two requests upgrading it to FOR UPDATE
            // deadlock each other (see the T03 plan).
            $this->connection->affectingStatement(
                'insert into `ticket_sequences` (`year`, `last_number`, `created_at`, `updated_at`) '
                .'values (?, 1, ?, ?) '
                .'on duplicate key update `last_number` = `last_number` + 1, `updated_at` = ?',
                [$year, $now, $now, $now],
            );

            // A locking read returns the row as this transaction just wrote it, never an older
            // REPEATABLE READ snapshot; the lock is already ours, so it doesn't wait.
            return (int) $this->connection->table('ticket_sequences')
                ->where('year', $year)
                ->lockForUpdate()
                ->value('last_number');
        });

        // %06d is a minimum width: number 1,000,000 renders as seven digits instead of wrapping.
        return sprintf('%s-%d-%06d', $prefix, $year, $number);
    }
}
```

- [ ] **Step 5: Run the test and confirm it passes**

```bash
./vendor/bin/pest tests/Feature/Services/Tickets/TicketNumberGeneratorTest.php
```
Expected: **PASS** (11 tests: 9 named tests, one of which is a 3-row dataset).

- [ ] **Step 6: Run the gate**

```bash
./vendor/bin/pint --test && ./vendor/bin/phpstan analyse --memory-limit=1G && ./vendor/bin/pest --parallel
```
Expected: Pint `PASS`, PHPStan `[OK] No errors`, Pest all green.

- [ ] **Step 7: Commit**

```bash
git add config/tickets.php app/Services/Tickets/TicketNumberGenerator.php tests/Feature/Services/Tickets/TicketNumberGeneratorTest.php
git commit -m "feat(tickets): generate ticket numbers from a per-year counter row" --trailer "Refs: T03.1" --trailer "Implements: TD-022"
```

---

## Subtask 3.2 — Two-connection proof of the row lock

**Approach (deterministic, no timing assumptions).** A real race between two PHP processes is flaky (TD-032 rejects `pcntl`), so the test reproduces the exact collision point, one step at a time, in a single process:

1. Connection **A** (default) opens a transaction and calls `next()`. It now holds the exclusive lock on the year row, uncommitted.
2. Connection **B** (`concurrentConnection()`, a separate MySQL session on the same database) sets `innodb_lock_wait_timeout = 1` for its session and calls `next()`. If allocation is serialized by the row lock, B **must** wait for A, and after 1 s it fails with MySQL error **1205** (`Lock wait timeout exceeded`). The failure isn't a deadlock (1213) and isn't a duplicate. If the lock were missing, B would return a number immediately and the test would fail.
3. A commits. B calls `next()` again and gets the **next** number. That proves B's first attempt left nothing behind and that the two connections can never hand out the same number.
4. A variant where A rolls back shows that B then receives the number A had, so the rollback released the lock and undid the increment, as seen from another session.

Both cases run for two starting states: the year row already exists (the normal case), and no row exists yet (the first ticket of a year, where A's uncommitted *insert* is what blocks B).

The file lives in `tests/Concurrency`, which uses `DatabaseTruncation` instead of `RefreshDatabase`. A `RefreshDatabase` transaction would keep A's setup rows invisible to B. This test is a proof of a guarantee that 3.1 already implements, so it passes on its first run. Step 3 adds a mutation check to show it can fail.

- [ ] **Step 1: Write the concurrency test** `tests/Concurrency/TicketNumberConcurrencyTest.php`

```php
<?php

declare(strict_types=1);

use App\Services\Tickets\TicketNumberGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    config(['business_hours.timezone' => 'Asia/Dhaka', 'tickets.number_prefix' => 'TKT']);
});

// A failed expectation must not leave connection A's lock behind for the next test's truncation.
afterEach(function (): void {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
});

dataset('starting sequence', [
    'the year row already exists' => [41],
    'first ticket of the year (no row yet)' => [null],
]);

function seedSequence(?int $lastNumber): int
{
    if ($lastNumber !== null) {
        DB::table('ticket_sequences')->insert([
            'year' => 2026,
            'last_number' => $lastNumber,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $lastNumber ?? 0;
}

function generatorOnSecondConnection(): TicketNumberGenerator
{
    $connection = concurrentConnection();
    // One second keeps the suite fast; any wait at all proves B was blocked by A's lock.
    $connection->statement('SET SESSION innodb_lock_wait_timeout = 1');

    return new TicketNumberGenerator($connection, app('config'));
}

function attemptAllocation(TicketNumberGenerator $generator, CarbonImmutable $at): ?QueryException
{
    try {
        $generator->next($at);
    } catch (QueryException $exception) {
        return $exception;
    }

    return null;
}

it('blocks a second connection while the first holds the year row, then hands it the next number', function (?int $lastNumber): void {
    $start = seedSequence($lastNumber);
    $at = CarbonImmutable::parse('2026-05-05 06:00:00', 'UTC');
    $generatorA = app(TicketNumberGenerator::class);
    $generatorB = generatorOnSecondConnection();

    DB::beginTransaction();
    $numberA = $generatorA->next($at);
    $blocked = attemptAllocation($generatorB, $at);
    DB::commit();

    expect($numberA)->toBe(sprintf('TKT-2026-%06d', $start + 1))
        ->and($blocked)->toBeInstanceOf(QueryException::class)
        ->and($blocked?->errorInfo[1] ?? null)->toBe(1205);

    expect($generatorB->next($at))->toBe(sprintf('TKT-2026-%06d', $start + 2))
        ->and((int) DB::table('ticket_sequences')->where('year', 2026)->value('last_number'))->toBe($start + 2);
})->with('starting sequence');

it('releases the lock and reuses the number when the first connection rolls back', function (?int $lastNumber): void {
    $start = seedSequence($lastNumber);
    $at = CarbonImmutable::parse('2026-05-05 06:00:00', 'UTC');
    $generatorA = app(TicketNumberGenerator::class);
    $generatorB = generatorOnSecondConnection();

    DB::beginTransaction();
    $numberA = $generatorA->next($at);
    DB::rollBack();

    expect($numberA)->toBe(sprintf('TKT-2026-%06d', $start + 1))
        ->and($generatorB->next($at))->toBe($numberA);
})->with('starting sequence');
```

- [ ] **Step 2: Run the concurrency test**

```bash
./vendor/bin/pest tests/Concurrency/TicketNumberConcurrencyTest.php
```
Expected: **PASS**, 4 tests. The two "blocks a second connection" rows each take about 1 s, which is the lock wait.

- [ ] **Step 3: Mutation check: show the test detects a missing lock (don't commit this change)**

Temporarily replace the body of the closure in `TicketNumberGenerator::next()` with a `MAX()+1`-style allocation that takes no lock. This is the race TD-022 rejects. Use exactly this code:

```php
            return (int) $this->connection->table('ticket_sequences')->where('year', $year)->value('last_number') + 1;
```
Then run:
```bash
./vendor/bin/pest tests/Concurrency/TicketNumberConcurrencyTest.php --filter="blocks a second connection"
```
Expected: **FAIL** with `Failed asserting that null is an instance of class Illuminate\Database\QueryException`. B isn't blocked, so it would hand out the same number as A. Then restore the file:
```bash
git checkout -- app/Services/Tickets/TicketNumberGenerator.php
```
and confirm with `./vendor/bin/pest tests/Concurrency/TicketNumberConcurrencyTest.php` → **PASS**.

- [ ] **Step 4: Run the gate**

```bash
./vendor/bin/pint --test && ./vendor/bin/phpstan analyse --memory-limit=1G && ./vendor/bin/pest --parallel
```
Expected: all green. Under `--parallel`, `concurrentConnection()` points B at the same per-process database as A.

- [ ] **Step 5: Commit**

```bash
git add tests/Concurrency/TicketNumberConcurrencyTest.php
git commit -m "test(tickets): prove ticket number row lock across connections" --trailer "Refs: T03.2" --trailer "Implements: TD-022, TD-032"
```

---

## Acceptance criteria

- [ ] `config/tickets.php` exists with only `number_prefix` (env `TICKET_NUMBER_PREFIX`, default `TKT`).
- [ ] `TicketNumberGenerator` is `final`, uses `declare(strict_types=1)`, and has exactly the public API in README §3.4: `next(CarbonImmutable $at): string`.
- [ ] Numbers have the format `{prefix}-{YYYY}-{%06d}`; padding grows past 999999 (`TKT-2026-1000000`).
- [ ] The year comes from `business_hours.timezone`: `2026-12-31 20:00 UTC` → `2027`.
- [ ] The counter restarts per year, and earlier years are unaffected.
- [ ] A rollback of the surrounding transaction rolls back the counter.
- [ ] A missing timezone config throws `InvalidArgumentException`.
- [ ] The two-connection test shows that B blocks with error 1205 while A holds the lock, and gets the next number after A commits. This holds both with and without a pre-existing year row.
- [ ] The prefix, timezone and SLA values aren't hard-coded anywhere in `app/`.

## Verification

```bash
./vendor/bin/pint --test
./vendor/bin/phpstan analyse --memory-limit=1G
./vendor/bin/pest --parallel
./vendor/bin/pest tests/Feature/Services/Tickets tests/Concurrency/TicketNumberConcurrencyTest.php
```
Expected: Pint passes, PHPStan prints `[OK] No errors`, all Pest tests pass, and the last command reports 15 passing tests (11 + 4). `resources/js` is untouched, so no frontend build is needed.

---

## Contract notes for controller

1. **Resolved: approved; TD-022 and spec §5 are being amended by the controller.** The spec's `INSERT IGNORE` → `SELECT … FOR UPDATE` → `UPDATE` sequence takes a shared lock on the duplicate key and then upgrades to exclusive, which deadlocks (MySQL 1213) when two tickets are created concurrently. This plan uses `INSERT … ON DUPLICATE KEY UPDATE last_number = last_number + 1` followed by `SELECT … FOR UPDATE`. The semantics are the same (per-year row, row-lock serialization, rollback undoes the increment), without the deadlock. Please amend TD-022's wording and spec §5's pseudo-code.
2. **Cross-wave config dependency.** The generator reads `business_hours.timezone`, which T02 creates in the same wave. T03's tests set the key, and production paths only exist after T05, which depends on both. The generator uses `Config::string()` so a missing key fails loudly instead of defaulting.
3. **Constructor signature.** README §3.4 lists only `next()`. The constructor `(ConnectionInterface $connection, Illuminate\Config\Repository $config)` is autowired. It's injectable so the concurrency test can run the generator on a second connection, as TD-032 requires.
4. **New env key** `TICKET_NUMBER_PREFIX`. T00's `.env.example` already contains it.
