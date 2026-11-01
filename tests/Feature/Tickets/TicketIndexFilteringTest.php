<?php

declare(strict_types=1);

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->customer = User::factory()->customer()->create();
    $this->other = User::factory()->customer()->create();
    $this->own = Ticket::factory()->status(TicketStatus::Open)->create(['customer_id' => $this->customer->id, 'created_by_id' => $this->customer->id]);
    $this->foreign = Ticket::factory()->status(TicketStatus::Open)->create(['customer_id' => $this->other->id, 'created_by_id' => $this->other->id]);
});

it('never lets a customer widen visibility through customer_id', function (): void {
    Sanctum::actingAs($this->customer, ['*']);
    $this->getJson(route('api.v1.tickets.index', ['customer_id' => $this->other->id]))
        ->assertOk()
        ->assertJsonCount(0, 'data');
    $this->actingAs($this->customer)
        ->get(route('tickets.index', ['customer_id' => $this->other->id]))
        ->assertInertia(fn (Assert $page) => $page->component('tickets/Index')->has('tickets.data', 0));
});

it('finds a ticket by number in any status from the list and the global search', function (string $role, array $query): void {
    $closed = Ticket::factory()->status(TicketStatus::Closed)->create([
        'number' => 'TKT-2026-000777',
        'customer_id' => $this->customer->id,
        'created_by_id' => $this->customer->id,
    ]);
    $actor = match ($role) {
        'admin' => $this->admin,
        'agent' => User::factory()->agent()->create(),
        default => $this->customer,
    };

    $this->actingAs($actor)
        ->get(route('tickets.index', $query))
        ->assertInertia(fn (Assert $page) => $page
            ->has('tickets.data', 1)
            ->where('tickets.data.0.number', $closed->number)
            ->where('scope', 'all'));
    Sanctum::actingAs($actor, ['*']);
    $this->getJson(route('api.v1.tickets.index', $query))
        ->assertOk()
        ->assertJsonPath('data.0.number', $closed->number)
        ->assertJsonCount(1, 'data');
})->with(['admin', 'agent', 'owning customer'])->with([
    'number filter on the active list' => [['scope' => 'active', 'number' => 'tkt-2026-000777']],
    'global search' => [['q' => 'TKT-2026-000777']],
    'global search without the prefix' => [['q' => '2026-000777']],
]);

it('drops an agent\'s own-queue default when they look up a colleague\'s ticket by number', function (): void {
    $agent = User::factory()->agent()->create();
    $colleague = User::factory()->agent()->create();
    $ticket = Ticket::factory()->status(TicketStatus::InProgress)->create(['number' => 'TKT-2026-000888', 'assigned_agent_id' => $colleague->id]);

    $this->actingAs($agent)
        ->get(route('tickets.index', ['scope' => 'active', 'assigned_agent_id' => $agent->id, 'number' => '2026-000888']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('tickets.data', 1)
            ->where('tickets.data.0.number', $ticket->number)
            ->missing('filters.assigned_agent_id'));
});

it('never lets a customer find another customer\'s ticket by its number', function (array $query): void {
    $query = array_map(fn (string $value): string => str_replace('{number}', $this->foreign->number, $value), $query);

    $this->actingAs($this->customer)
        ->get(route('tickets.index', $query))
        ->assertInertia(fn (Assert $page) => $page->has('tickets.data', 0));
    Sanctum::actingAs($this->customer, ['*']);
    $this->getJson(route('api.v1.tickets.index', $query))
        ->assertOk()
        ->assertJsonCount(0, 'data');
})->with([
    'number filter' => [['scope' => 'all', 'number' => '{number}']],
    'global search' => [['q' => '{number}']],
]);

it('rejects a sort column outside the whitelist', function (string $sort): void {
    Sanctum::actingAs($this->admin, ['*']);
    $this->getJson(route('api.v1.tickets.index', ['sort' => $sort]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('sort');
})->with(['password', 'customer_id', 'created_at; drop table tickets', '--created_at', 'subject']);

it('filters by SLA state using the current time', function (): void {
    $now = CarbonImmutable::parse('2026-11-02 06:00:00', 'UTC');
    $this->travelTo($now);
    Ticket::query()->update(['sla_warning_at' => $now->addDays(2), 'sla_due_at' => $now->addDays(3)]);
    $breached = Ticket::factory()->status(TicketStatus::Open)->create(['sla_warning_at' => $now->subHours(3), 'sla_due_at' => $now->subHour()]);
    Ticket::factory()->status(TicketStatus::InProgress)->create(['sla_warning_at' => $now->addHours(2), 'sla_due_at' => $now->addHours(4)]);
    Sanctum::actingAs($this->admin, ['*']);
    $response = $this->getJson(route('api.v1.tickets.index', ['sla_state' => 'breached']))->assertOk();

    expect(collect($response->json('data'))->pluck('number')->all())->toBe([$breached->number]);
});

it('passes the validated filters and the filter options to the index page', function (): void {
    $agent = User::factory()->agent()->create();
    $this->actingAs($this->admin)
        ->get(route('tickets.index', ['status' => ['open'], 'sort' => '-priority', 'q' => 'printer']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tickets/Index')
            ->where('filters.status', ['open'])
            ->where('filters.sort', '-priority')
            ->where('filters.q', 'printer')
            ->has('filterOptions.statuses', 3)
            ->has('filterOptions.priorities', 4)
            ->has('filterOptions.slaStates', 5)
            ->has('filterOptions.sorts', 8)
            ->has('filterOptions.categories')
            ->where('filterOptions.agents.0.id', $agent->id)
            ->where('filterOptions.showPeopleFilters', true));
});

it('gives customers no agent list and no people filters', function (): void {
    User::factory()->agent()->create();
    $this->actingAs($this->customer)
        ->get(route('tickets.index', ['scope' => 'resolved']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('filterOptions.statuses', 2)
            ->where('filterOptions.agents', [])
            ->where('filterOptions.showPeopleFilters', false));
});

it('opens an agent on their own active queue when no filter is chosen', function (): void {
    $agent = User::factory()->agent()->create();

    $this->actingAs($agent)
        ->get(route('tickets.index'))
        ->assertRedirect(route('tickets.index', ['scope' => 'active', 'assigned_agent_id' => $agent->id]));
});

it('keeps an explicit queue choice and the default for other roles', function (Closure $actor, array $query): void {
    $this->actingAs($actor())
        ->get(route('tickets.index', $query))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('tickets/Index'));
})->with([
    'agent choosing all active' => [fn () => User::factory()->agent()->create(), ['scope' => 'active']],
    'admin with no filter' => [fn () => User::factory()->admin()->create(), []],
    'customer with no filter' => [fn () => User::factory()->customer()->create(), []],
]);
