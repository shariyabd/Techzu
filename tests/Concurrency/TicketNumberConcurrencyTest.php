<?php

declare(strict_types=1);

use App\Services\Tickets\TicketNumberGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    config(['business_hours.timezone' => 'Asia/Dhaka', 'tickets.number_prefix' => 'TKT']);
});

afterEach(function (): void {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
});

dataset('starting sequence', [
    'the year row already exists' => [41],
    'first ticket of the year (no row yet)' => [null],
]);

function tncSeedSequence(?int $lastNumber): int
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

function tncGeneratorOnSecondConnection(): TicketNumberGenerator
{
    $connection = concurrentConnection();
    $connection->statement('SET SESSION innodb_lock_wait_timeout = 1');

    return new TicketNumberGenerator($connection, app('config'));
}

function tncAttemptAllocation(TicketNumberGenerator $generator, CarbonImmutable $at): ?QueryException
{
    try {
        $generator->next($at);
    } catch (QueryException $exception) {
        return $exception;
    }

    return null;
}

it('blocks a second connection while the first holds the year row, then hands it the next number', function (?int $lastNumber): void {
    $start = tncSeedSequence($lastNumber);
    $at = CarbonImmutable::parse('2026-05-05 06:00:00', 'UTC');
    $generatorA = app(TicketNumberGenerator::class);
    $generatorB = tncGeneratorOnSecondConnection();

    DB::beginTransaction();
    $numberA = $generatorA->next($at);
    $blocked = tncAttemptAllocation($generatorB, $at);
    DB::commit();

    expect($numberA)->toBe(sprintf('TKT-2026-%06d', $start + 1))
        ->and($blocked)->toBeInstanceOf(QueryException::class)
        ->and($blocked?->errorInfo[1] ?? null)->toBe(1205);

    expect($generatorB->next($at))->toBe(sprintf('TKT-2026-%06d', $start + 2))
        ->and((int) DB::table('ticket_sequences')->where('year', 2026)->value('last_number'))->toBe($start + 2);
})->with('starting sequence');

it('releases the lock and reuses the number when the first connection rolls back', function (?int $lastNumber): void {
    $start = tncSeedSequence($lastNumber);
    $at = CarbonImmutable::parse('2026-05-05 06:00:00', 'UTC');
    $generatorA = app(TicketNumberGenerator::class);
    $generatorB = tncGeneratorOnSecondConnection();

    DB::beginTransaction();
    $numberA = $generatorA->next($at);
    DB::rollBack();

    expect($numberA)->toBe(sprintf('TKT-2026-%06d', $start + 1))
        ->and($generatorB->next($at))->toBe($numberA);
})->with('starting sequence');
