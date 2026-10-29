<?php

declare(strict_types=1);

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tickets\TicketFilter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-29 06:00:00', 'UTC'));
    $this->admin = User::factory()->admin()->create();
    $this->people = [
        'c1' => User::factory()->customer()->create(),
        'c2' => User::factory()->customer()->create(),
        'a1' => User::factory()->agent()->create(),
        'a2' => User::factory()->agent()->create(),
    ];
    $this->categories = ['cat1' => Category::factory()->create(), 'cat2' => Category::factory()->create()];
    $make = fn (string $number, TicketStatus $status, TicketPriority $priority, string $customer, string $category, ?string $agent, string $createdAt, string $updatedAt, string $dueAt): Ticket => Ticket::factory()
        ->status($status)
        ->priority($priority)
        ->create([
            'number' => $number,
            'customer_id' => $this->people[$customer]->id,
            'created_by_id' => $this->people[$customer]->id,
            'category_id' => $this->categories[$category]->id,
            'assigned_agent_id' => $agent === null ? null : $this->people[$agent]->id,
            'created_at' => CarbonImmutable::parse($createdAt, 'UTC'),
            'updated_at' => CarbonImmutable::parse($updatedAt, 'UTC'),
            'sla_due_at' => CarbonImmutable::parse($dueAt, 'UTC'),
            'sla_warning_at' => CarbonImmutable::parse($dueAt, 'UTC')->subHour(),
            'sla_paused_at' => null,
        ]);
    $this->tickets = [
        't1' => $make('TKT-2026-000101', TicketStatus::Open, TicketPriority::Low, 'c1', 'cat1', 'a1', '2026-10-01 06:00:00', '2026-10-28 06:00:00', '2026-11-04 06:00:00'),
        't2' => $make('TKT-2026-000102', TicketStatus::InProgress, TicketPriority::High, 'c1', 'cat2', null, '2026-10-10 06:00:00', '2026-10-05 06:00:00', '2026-11-01 06:00:00'),
        't3' => $make('TKT-2026-000103', TicketStatus::WaitingForCustomer, TicketPriority::Critical, 'c2', 'cat1', 'a2', '2026-10-20 06:00:00', '2026-10-21 06:00:00', '2026-11-02 06:00:00'),
        't4' => $make('TKT-2026-000104', TicketStatus::Resolved, TicketPriority::Medium, 'c2', 'cat2', 'a1', '2026-10-25 06:00:00', '2026-10-26 06:00:00', '2026-11-03 06:00:00'),
    ];
    $this->numbers = fn (array $keys): array => array_map(fn (string $key): string => $this->tickets[$key]->number, $keys);
    $this->run = fn (array $filters, ?User $viewer = null): Builder => app(TicketFilter::class)
        ->apply(Ticket::query(), $filters, $viewer ?? $this->admin, CarbonImmutable::now());
});

it('applies each filter on its own and in combination', function (array $filters, array $expected): void {
    expect(($this->run)($filters)->pluck('number')->sort()->values()->all())->toBe(($this->numbers)($expected));
})->with([
    'no filters' => [fn (): array => [], ['t1', 't2', 't3', 't4']],
    'exact number' => [fn (): array => ['number' => 'TKT-2026-000102'], ['t2']],
    'number prefix' => [fn (): array => ['number' => 'TKT-2026-00010'], ['t1', 't2', 't3', 't4']],
    'number is case-insensitive' => [fn (): array => ['number' => 'tkt-2026-000103'], ['t3']],
    'number LIKE metacharacters are literal' => [fn (): array => ['number' => 'TKT-2026-0001_1'], []],
    'status (multiple)' => [fn (): array => ['status' => ['open', 'in_progress']], ['t1', 't2']],
    'priority' => [fn (): array => ['priority' => ['critical']], ['t3']],
    'category id as a query-string string' => [fn (): array => ['category_id' => (string) $this->categories['cat1']->id], ['t1', 't3']],
    'assigned agent' => [fn (): array => ['assigned_agent_id' => (string) $this->people['a1']->id], ['t1', 't4']],
    'unassigned as "1"' => [fn (): array => ['unassigned' => '1'], ['t2']],
    'unassigned "0" is ignored' => [fn (): array => ['unassigned' => '0'], ['t1', 't2', 't3', 't4']],
    'customer' => [fn (): array => ['customer_id' => (string) $this->people['c2']->id], ['t3', 't4']],
    'created from' => [fn (): array => ['created_from' => '2026-10-10'], ['t2', 't3', 't4']],
    'created to' => [fn (): array => ['created_to' => '2026-10-10'], ['t1', 't2']],
    'created range' => [fn (): array => ['created_from' => '2026-10-02', 'created_to' => '2026-10-24'], ['t2', 't3']],
    'status + category' => [fn (): array => ['status' => ['open', 'in_progress'], 'category_id' => $this->categories['cat1']->id], ['t1']],
    'customer + status + date' => [fn (): array => ['customer_id' => $this->people['c1']->id, 'status' => ['in_progress'], 'created_from' => '2026-10-05'], ['t2']],
    'unassigned + agent never matches' => [fn (): array => ['unassigned' => '1', 'assigned_agent_id' => $this->people['a1']->id], []],
    'keyword of only operators is ignored' => [fn (): array => ['q' => '+-()"'], ['t1', 't2', 't3', 't4']],
]);

it('sorts by whitelisted columns with id as the tie-breaker', function (string $sort, array $expected): void {
    expect(($this->run)(['sort' => $sort])->pluck('number')->all())->toBe(($this->numbers)($expected));
})->with([
    'default is newest first' => ['-created_at', ['t4', 't3', 't2', 't1']],
    'created ascending' => ['created_at', ['t1', 't2', 't3', 't4']],
    'priority by rank descending' => ['-priority', ['t3', 't2', 't4', 't1']],
    'priority by rank ascending' => ['priority', ['t1', 't4', 't2', 't3']],
    'due soonest first' => ['sla_due_at', ['t2', 't3', 't4', 't1']],
    'recently updated first' => ['-updated_at', ['t1', 't4', 't3', 't2']],
]);

it('treats created dates as whole days in the business timezone', function (): void {
    $late = Ticket::factory()->create([
        'number' => 'TKT-2026-000199',
        'created_at' => CarbonImmutable::parse('2026-10-09 19:00:00', 'UTC'),
    ]);

    expect(($this->run)(['created_from' => '2026-10-10', 'created_to' => '2026-10-10'])->pluck('number')->all())
        ->toContain($late->number)
        ->and(($this->run)(['created_to' => '2026-10-09'])->pluck('number')->all())
        ->not->toContain($late->number);
});

it('applies visibility before every other filter', function (): void {
    $customer = $this->people['c1'];

    expect(($this->run)(['customer_id' => (string) $this->people['c2']->id], $customer)->count())->toBe(0)
        ->and(($this->run)([], $customer)->pluck('number')->sort()->values()->all())->toBe(($this->numbers)(['t1', 't2']));
});
