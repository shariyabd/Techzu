<?php

declare(strict_types=1);

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Dashboard\DashboardStats;
use App\Services\Sla\SlaQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-10-29 06:00:00', 'UTC'));
    $now = CarbonImmutable::now();
    $category = Category::factory()->create();
    $customer = User::factory()->customer()->create();
    $this->alpha = User::factory()->agent([$category->id])->create(['name' => 'Agent Alpha']);
    $this->bravo = User::factory()->agent([$category->id])->create(['name' => 'Agent Bravo']);
    $this->charlie = User::factory()->agent([$category->id])->create(['name' => 'Agent Charlie']);
    $make = fn (TicketStatus $status, TicketPriority $priority, ?User $agent, CarbonImmutable $warning, CarbonImmutable $due, array $extra = []): Ticket => Ticket::factory()->create([
        'category_id' => $category->id,
        'customer_id' => $customer->id,
        'status' => $status,
        'priority' => $priority,
        'assigned_agent_id' => $agent?->id,
        'sla_warning_at' => $warning,
        'sla_due_at' => $due,
        'sla_paused_at' => null,
        'resolved_at' => null,
        'closed_at' => null,
        ...$extra,
    ]);
    $make(TicketStatus::Open, TicketPriority::Low, null, $now->addHours(2), $now->addHours(4));
    $make(TicketStatus::InProgress, TicketPriority::Critical, $this->alpha, $now->subHours(2), $now->subHour());
    $make(TicketStatus::WaitingForCustomer, TicketPriority::High, $this->alpha, $now->subHours(3), $now->subHour(), ['sla_paused_at' => $now->subHours(5)]);
    $make(TicketStatus::Open, TicketPriority::Medium, $this->bravo, $now->subMinutes(10), $now->addHour());
    $make(TicketStatus::Open, TicketPriority::Critical, $this->bravo, $now->addHour(), $now->addHours(2));
    $make(TicketStatus::Resolved, TicketPriority::Critical, $this->alpha, $now->subDays(4), $now->subDays(3), ['resolved_at' => $now->subDays(2)]);
    $make(TicketStatus::Closed, TicketPriority::Low, $this->bravo, $now->subDays(45), $now->subDays(44), ['resolved_at' => $now->subDays(40), 'closed_at' => $now->subDays(39)]);
    $make(TicketStatus::Closed, TicketPriority::High, $this->alpha, $now->subDays(3), $now->subDays(2), ['resolved_at' => $now->subDay(), 'closed_at' => $now->subDay()]);
});

it('computes the totals with the same SLA semantics as SlaQuery', function (): void {
    $totals = app(DashboardStats::class)->summary()['totals'];
    expect($totals)->toBe(['open' => 5, 'unassigned' => 1, 'critical' => 2, 'breached' => 1, 'approaching' => 1, 'resolved' => 2])
        ->and($totals['breached'])->toBe(SlaQuery::whereBreached(Ticket::query(), CarbonImmutable::now())->count())
        ->and($totals['approaching'])->toBe(SlaQuery::whereApproaching(Ticket::query(), CarbonImmutable::now())->count());
});

it('lists every priority, critical first, counting active tickets only', function (): void {
    expect(app(DashboardStats::class)->summary()['by_priority'])->toBe([
        ['priority' => 'critical', 'count' => 2],
        ['priority' => 'high', 'count' => 1],
        ['priority' => 'medium', 'count' => 1],
        ['priority' => 'low', 'count' => 1],
    ]);
});

it('lists active and breached tickets per agent, including idle agents', function (): void {
    expect(app(DashboardStats::class)->summary()['by_agent'])->toBe([
        ['agent_id' => $this->alpha->id, 'name' => 'Agent Alpha', 'active' => 2, 'breached' => 1],
        ['agent_id' => $this->bravo->id, 'name' => 'Agent Bravo', 'active' => 2, 'breached' => 0],
        ['agent_id' => $this->charlie->id, 'name' => 'Agent Charlie', 'active' => 0, 'breached' => 0],
    ]);
});

it('runs three queries on a cold cache', function (): void {
    DB::enableQueryLog();
    app(DashboardStats::class)->summary();
    $cold = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($cold)->toBe(3);
});

it('serves cached figures for 60 seconds', function (): void {
    $first = app(DashboardStats::class)->summary();
    Ticket::factory()->create(['status' => TicketStatus::Open, 'assigned_agent_id' => null]);
    expect(app(DashboardStats::class)->summary())->toBe($first);
    $this->travel(61)->seconds();
    expect(app(DashboardStats::class)->summary()['totals']['open'])->toBe(6);
});

it("returns the viewer's active assigned tickets, soonest deadline first", function (): void {
    expect(app(DashboardStats::class)->myTickets($this->alpha)->pluck('status')->all())
        ->toBe([TicketStatus::InProgress, TicketStatus::WaitingForCustomer])
        ->and(app(DashboardStats::class)->myTickets($this->charlie))->toHaveCount(0);
});
