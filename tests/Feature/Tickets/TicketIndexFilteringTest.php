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
