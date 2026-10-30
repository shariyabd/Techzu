<?php

declare(strict_types=1);

use App\Enums\ActivityType;
use App\Enums\SlaState;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Events\TicketSlaApproaching;
use App\Events\TicketSlaBreached;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketCreatedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/** @param  list<string>  $columns */
function indexOnColumns(string $table, array $columns): string
{
    $rows = DB::select(
        'SELECT INDEX_NAME AS index_name, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns
           FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
          GROUP BY INDEX_NAME',
        [$table],
    );

    foreach ($rows as $row) {
        if ($row->index_columns === implode(',', $columns)) {
            return (string) $row->index_name;
        }
    }

    throw new RuntimeException(sprintf('No index on %s(%s), which TD-021 promises.', $table, implode(', ', $columns)));
}

/** @return list<array{query: string, bindings: array<int, mixed>}> */
function capturedSelects(Closure $run): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $run();
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    return array_values(array_filter(
        array_map(static fn (array $entry): array => ['query' => (string) $entry['query'], 'bindings' => $entry['bindings']], $log),
        static fn (array $entry): bool => str_starts_with(strtolower($entry['query']), 'select'),
    ));
}

/**
 * @param  list<array{query: string, bindings: array<int, mixed>}>  $selects
 * @param  list<string>  $needles
 * @return array{query: string, bindings: array<int, mixed>}
 */
function selectFrom(array $selects, string $table, array $needles = []): array
{
    foreach ($selects as $select) {
        if (! str_contains($select['query'], "from `{$table}`")) {
            continue;
        }

        foreach ($needles as $needle) {
            if (! str_contains($select['query'], $needle)) {
                continue 2;
            }
        }

        return $select;
    }

    throw new RuntimeException("No select from {$table} containing [".implode(', ', $needles)."]:\n".implode("\n", array_column($selects, 'query')));
}

/**
 * @param  array{query: string, bindings: array<int, mixed>}  $select
 * @return array{tables: array<string, array{key: ?string, access: ?string}>, plan: string}
 */
function explainSelect(array $select): array
{
    $row = (array) DB::selectOne('EXPLAIN FORMAT=JSON '.$select['query'], $select['bindings']);
    $json = (string) ($row['EXPLAIN'] ?? reset($row));
    $tables = [];

    $walk = function (array $node) use (&$walk, &$tables): void {
        if (isset($node['table_name']) && is_string($node['table_name']) && ! array_key_exists($node['table_name'], $tables)) {
            $tables[$node['table_name']] = [
                'key' => isset($node['key']) ? (string) $node['key'] : null,
                'access' => isset($node['access_type']) ? (string) $node['access_type'] : null,
            ];
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                $walk($child);
            }
        }
    };
    $walk(json_decode($json, true, 512, JSON_THROW_ON_ERROR));

    return ['tables' => $tables, 'plan' => $json];
}

/**
 * @param  array{query: string, bindings: array<int, mixed>}  $select
 * @param  list<string>  $columns
 */
function expectIndex(array $select, string $table, array $columns): void
{
    $explain = explainSelect($select);

    expect($explain['tables'][$table]['key'] ?? null)->toBe(indexOnColumns($table, $columns), $explain['plan']);
}

/** @param  list<string>  $tables */
function analyzeTables(array $tables): void
{
    foreach ($tables as $table) {
        concurrentConnection()->statement("ANALYZE TABLE `{$table}`");
    }
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-29 06:00:00', 'UTC'));
    $now = CarbonImmutable::now();

    $categories = Category::factory()->count(4)->create()->pluck('id')->all();
    $this->categoryId = $categories[1];
    $this->admin = User::factory()->admin()->create();
    $this->agent = User::factory()->agent($categories)->create();
    $this->customer = User::factory()->customer()->create();
    $otherAgent = User::factory()->agent($categories)->create();
    $customers = User::factory()->customer()->count(20)->create()->pluck('id')->all();

    $active = TicketStatus::active();
    $priorities = TicketPriority::cases();
    $rows = [];

    for ($i = 1; $i <= 3000; $i++) {
        $finished = $i % 20 < 17;
        $status = $finished ? ($i % 2 === 0 ? TicketStatus::Closed : TicketStatus::Resolved) : $active[$i % 3];
        $created = $now->subMinutes($i * 37);
        [$due, $warning] = match (true) {
            $finished => [$created->addHours(8), $created->addHours(6)],
            $i % 7 === 0 => [$now->subHour(), $now->subHours(3)],
            $i % 7 === 1 => [$now->addMinutes(90), $now->subMinutes(30)],
            default => [$now->addDays(2), $now->addDay()],
        };
        $customerId = $i % 97 === 0 ? $this->customer->id : $customers[$i % 20];

        $rows[] = [
            'number' => sprintf('TKT-2026-%06d', $i),
            'subject' => "Ticket {$i} about a ".($i % 5 === 0 ? 'printer' : 'login').' problem',
            'description' => 'Generated for the EXPLAIN audit.',
            'priority' => $priorities[$i % 4]->value,
            'status' => $status->value,
            'category_id' => $categories[intdiv($i, 4) % 4],
            'customer_id' => $customerId,
            'created_by_id' => $customerId,
            'assigned_agent_id' => $i % 47 === 0 ? $this->agent->id : ($i % 3 === 0 ? $otherAgent->id : null),
            'sla_started_at' => $created,
            'sla_due_at' => $due,
            'sla_warning_at' => $warning,
            'sla_paused_at' => $status === TicketStatus::WaitingForCustomer ? $now->subDay() : null,
            'escalation_level' => 0,
            'resolved_at' => $finished ? $created->addHours(4) : null,
            'closed_at' => $status === TicketStatus::Closed ? $created->addHours(5) : null,
            'created_at' => $created,
            'updated_at' => $created,
        ];
    }

    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('tickets')->insert($chunk);
    }

    analyzeTables(['tickets']);
});

it("serves a customer's ticket list from tickets(customer_id, created_at)", function (): void {
    Sanctum::actingAs($this->customer, ['*']);

    $selects = capturedSelects(fn () => $this->getJson(route('api.v1.tickets.index'))->assertOk());

    expectIndex(selectFrom($selects, 'tickets', ['limit']), 'tickets', ['customer_id', 'created_at']);
});

it('serves the default staff ticket list without a full table scan', function (): void {
    Sanctum::actingAs($this->admin, ['*']);

    $selects = capturedSelects(fn () => $this->getJson(route('api.v1.tickets.index'))->assertOk());
    $explain = explainSelect(selectFrom($selects, 'tickets', ['limit']));

    expect($explain['tables']['tickets']['access'] ?? 'ALL')->not->toBe('ALL', $explain['plan']);
});

dataset('indexed ticket filters', [
    'my tickets' => [fn (): array => ['assigned_agent_id' => $this->agent->id], ['assigned_agent_id', 'status']],
    'priority' => [fn (): array => ['priority' => [TicketPriority::Critical->value]], ['priority', 'status']],
    'category' => [fn (): array => ['category_id' => $this->categoryId], ['category_id', 'status']],
    'breached' => [fn (): array => ['sla_state' => SlaState::Breached->value], ['status', 'sla_due_at']],
    'approaching' => [fn (): array => ['sla_state' => SlaState::Approaching->value], ['status', 'sla_warning_at']],
    'created date range' => [fn (): array => ['created_from' => '2026-10-19', 'created_to' => '2026-10-19'], ['created_at']],
    'ticket number' => [fn (): array => ['number' => 'TKT-2026-0012'], ['number']],
]);

it('serves each ticket filter from its TD-021 index', function (array $filters, array $columns): void {
    Sanctum::actingAs($this->admin, ['*']);

    $selects = capturedSelects(fn () => $this->getJson(route('api.v1.tickets.index', $filters))->assertOk());

    expectIndex(selectFrom($selects, 'tickets', ['limit']), 'tickets', $columns);
})->with('indexed ticket filters');

it('serves the keyword search from the tickets full-text index', function (): void {
    Sanctum::actingAs($this->admin, ['*']);

    $selects = capturedSelects(fn () => $this->getJson(route('api.v1.tickets.index', ['q' => 'printer']))->assertOk());
    $explain = explainSelect(selectFrom($selects, 'tickets', ['match (']));

    expect($explain['tables']['tickets']['access'] ?? null)->toBe('fulltext', $explain['plan']);
});

it('runs the escalation scan from the SLA timestamp indexes', function (): void {
    Event::fake([TicketSlaApproaching::class, TicketSlaBreached::class]);

    $selects = capturedSelects(fn () => $this->artisan('sla:scan')->assertSuccessful()->run());

    expectIndex(selectFrom($selects, 'tickets', ['`sla_warning_at` <=']), 'tickets', ['status', 'sla_warning_at']);
    expectIndex(selectFrom($selects, 'tickets', ['`sla_due_at` <=']), 'tickets', ['status', 'sla_due_at']);
});

it('builds the dashboard without a full scan of tickets', function (): void {
    Cache::flush();
    Sanctum::actingAs($this->admin, ['*']);

    $ticketSelects = collect(capturedSelects(fn () => $this->getJson(route('api.v1.dashboard'))->assertOk()))
        ->filter(fn (array $select): bool => str_contains($select['query'], '`tickets`'));
    $fullScans = $ticketSelects
        ->map(fn (array $select): array => explainSelect($select) + ['sql' => $select['query']])
        ->filter(fn (array $explain): bool => ($explain['tables']['tickets']['access'] ?? null) === 'ALL')
        ->map(fn (array $explain): string => $explain['sql'].PHP_EOL.$explain['plan'])
        ->values()
        ->all();

    expect($ticketSelects)->not->toBeEmpty()
        ->and($fullScans)->toBe([]);
});

it('serves the activity timeline from activity_log(subject_type, subject_id, created_at)', function (): void {
    $ticket = Ticket::query()->where('customer_id', $this->customer->id)->firstOrFail();
    $now = CarbonImmutable::now();
    $rows = [];

    foreach (Ticket::query()->pluck('id') as $index => $ticketId) {
        for ($n = 0; $n < ($ticketId === $ticket->id ? 40 : 1); $n++) {
            $rows[] = [
                'log_name' => 'ticket',
                'description' => 'Generated',
                'subject_type' => $ticket->getMorphClass(),
                'subject_id' => $ticketId,
                'event' => ActivityType::Updated->value,
                'properties' => '{}',
                'created_at' => $now->subMinutes($index + $n),
                'updated_at' => $now->subMinutes($index + $n),
            ];
        }
    }

    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('activity_log')->insert($chunk);
    }

    analyzeTables(['activity_log']);

    Sanctum::actingAs($this->admin, ['*']);
    $selects = capturedSelects(fn () => $this->getJson(route('api.v1.tickets.activities', $ticket))->assertOk());

    expectIndex(selectFrom($selects, 'activity_log', ['limit']), 'activity_log', ['subject_type', 'subject_id', 'created_at']);
});

it('serves the notification inbox from notifications(notifiable_type, notifiable_id, created_at)', function (array $query): void {
    $userIds = User::query()->pluck('id')->all();
    $now = CarbonImmutable::now();
    $rows = [];

    for ($i = 1; $i <= 3000; $i++) {
        $rows[] = [
            'id' => (string) Str::uuid(),
            'type' => TicketCreatedNotification::class,
            'notifiable_type' => $this->customer->getMorphClass(),
            'notifiable_id' => $i % 20 === 0 ? $this->customer->id : $userIds[$i % count($userIds)],
            'data' => json_encode(['ticket_number' => sprintf('TKT-2026-%06d', $i), 'title' => 'Created', 'body' => 'Created', 'url' => '/'], JSON_THROW_ON_ERROR),
            'read_at' => $i % 3 === 0 ? $now : null,
            'created_at' => $now->subMinutes($i),
            'updated_at' => $now->subMinutes($i),
        ];
    }

    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('notifications')->insert($chunk);
    }

    analyzeTables(['notifications']);

    Sanctum::actingAs($this->customer, ['*']);
    $selects = capturedSelects(fn () => $this->getJson(route('api.v1.notifications.index', $query))->assertOk());

    expectIndex(selectFrom($selects, 'notifications', ['limit']), 'notifications', ['notifiable_type', 'notifiable_id', 'created_at']);
})->with([
    'all' => [[]],
    'unread only' => [['unread' => 1]],
]);
