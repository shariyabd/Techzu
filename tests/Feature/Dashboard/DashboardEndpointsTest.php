<?php

declare(strict_types=1);

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-10-29 06:00:00', 'UTC'));
    $this->agent = User::factory()->agent()->create(['name' => 'Agent Alpha']);
    $this->breached = Ticket::factory()->breached()->assignedTo($this->agent)->create([
        'status' => TicketStatus::InProgress,
        'priority' => TicketPriority::Critical,
    ]);
    Ticket::factory()->create(['status' => TicketStatus::Open, 'assigned_agent_id' => null]);
});

it('renders the dashboard props', function (): void {
    $this->withoutVite()->actingAs($this->agent)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/Index', false)
            ->has('stats.totals')
            ->has('stats.by_priority', 4)
            ->has('stats.by_agent')
            ->where('myTickets.0.number', $this->breached->number)
            ->where('myTickets.0.sla.state.value', 'breached'));
});

it('returns the same figures from the API', function (): void {
    Sanctum::actingAs($this->agent, ['*']);
    $this->getJson(route('api.v1.dashboard'))
        ->assertOk()
        ->assertJsonPath('data.totals.unassigned', 1)
        ->assertJsonPath('data.by_priority.0', ['priority' => 'critical', 'count' => 1])
        ->assertJsonPath('data.by_agent.0.agent_id', $this->agent->id)
        ->assertJsonPath('data.my_tickets.0.number', $this->breached->number)
        ->assertJsonStructure(['data' => ['totals', 'by_priority', 'by_agent', 'my_tickets' => [['number', 'subject', 'status', 'priority', 'sla']]]]);
});
