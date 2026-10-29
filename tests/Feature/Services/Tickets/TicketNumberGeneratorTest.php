<?php

declare(strict_types=1);

use App\Services\Tickets\TicketNumberGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    config(['business_hours.timezone' => 'Asia/Dhaka']);
});

function tngGenerator(): TicketNumberGenerator
{
    return app(TicketNumberGenerator::class);
}

function tngUtc(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'UTC');
}

function tngSequenceValue(int $year): ?int
{
    $value = DB::table('ticket_sequences')->where('year', $year)->value('last_number');

    return $value === null ? null : (int) $value;
}

it('formats the first number of a year with the prefix and six-digit padding', function (): void {
    expect(tngGenerator()->next(tngUtc('2026-03-10 08:00:00')))->toBe('TKT-2026-000001');
});

it('uses the configured prefix', function (): void {
    config(['tickets.number_prefix' => 'SUP']);

    expect(tngGenerator()->next(tngUtc('2026-03-10 08:00:00')))->toBe('SUP-2026-000001');
});

it('increments sequentially within a year', function (): void {
    $generator = tngGenerator();
    $at = tngUtc('2026-05-01 04:00:00');

    expect([$generator->next($at), $generator->next($at), $generator->next($at)])
        ->toBe(['TKT-2026-000001', 'TKT-2026-000002', 'TKT-2026-000003'])
        ->and(tngSequenceValue(2026))->toBe(3);
});

it('takes the year from the business timezone, not UTC', function (string $utcTime, string $expected): void {
    expect(tngGenerator()->next(tngUtc($utcTime)))->toBe($expected);
})->with([
    '2026-12-31 17:59:59 UTC is 23:59:59 in Dhaka (2026)' => ['2026-12-31 17:59:59', 'TKT-2026-000001'],
    '2026-12-31 18:00:00 UTC is midnight in Dhaka (2027)' => ['2026-12-31 18:00:00', 'TKT-2027-000001'],
    '2026-12-31 20:00:00 UTC is 02:00 in Dhaka (2027)' => ['2026-12-31 20:00:00', 'TKT-2027-000001'],
]);

it('restarts the counter for a new year and keeps the previous year intact', function (): void {
    $generator = tngGenerator();

    $numbers = [
        $generator->next(tngUtc('2026-12-31 17:00:00')),
        $generator->next(tngUtc('2026-12-31 17:30:00')),
        $generator->next(tngUtc('2026-12-31 20:00:00')),
        $generator->next(tngUtc('2026-06-01 06:00:00')),
    ];

    expect($numbers)->toBe(['TKT-2026-000001', 'TKT-2026-000002', 'TKT-2027-000001', 'TKT-2026-000003'])
        ->and(tngSequenceValue(2026))->toBe(3)
        ->and(tngSequenceValue(2027))->toBe(1);
});

it('grows past six digits instead of wrapping', function (): void {
    DB::table('ticket_sequences')->insert([
        'year' => 2026,
        'last_number' => 999998,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $generator = tngGenerator();
    $at = tngUtc('2026-07-07 07:00:00');

    expect($generator->next($at))->toBe('TKT-2026-999999')
        ->and($generator->next($at))->toBe('TKT-2026-1000000')
        ->and(tngSequenceValue(2026))->toBe(1000000);
});

it('rolls the counter back together with the surrounding transaction', function (): void {
    $generator = tngGenerator();
    $at = tngUtc('2026-08-08 08:00:00');

    try {
        DB::transaction(function () use ($generator, $at): void {
            expect($generator->next($at))->toBe('TKT-2026-000001');

            throw new RuntimeException('ticket insert failed');
        });
    } catch (RuntimeException) {
    }

    expect(tngSequenceValue(2026))->toBeNull()
        ->and($generator->next($at))->toBe('TKT-2026-000001');
});

it('fails loudly when the business timezone is not configured', function (): void {
    config(['business_hours.timezone' => null]);

    tngGenerator()->next(tngUtc('2026-01-01 00:00:00'));
})->throws(InvalidArgumentException::class);
