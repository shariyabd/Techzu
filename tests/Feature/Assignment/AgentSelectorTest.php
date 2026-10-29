<?php

declare(strict_types=1);

use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Assignment\AgentSelector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->now = CarbonImmutable::parse('2026-11-01 03:00:00', 'UTC');
    $this->travelTo($this->now);
    $this->billing = Category::factory()->create();
    $this->technical = Category::factory()->create();
});

function loadAgent(User $agent, int $active, int $finished = 0): User
{
    if ($active > 0) {
        Ticket::factory()->count($active)->status(TicketStatus::InProgress)->assignedTo($agent)->create();
    }

    if ($finished > 0) {
        Ticket::factory()->count($finished)->status(TicketStatus::Closed)->assignedTo($agent)->create();
    }

    return $agent;
}

dataset('selection rules', [
    'only agents of the ticket category' => [function (): array {
        User::factory()->agent([$this->technical->id])->create();
        $match = loadAgent(User::factory()->agent([$this->billing->id])->create(), 5);

        return [$this->billing, $match];
    }],
    'unavailable agents are skipped' => [function (): array {
        User::factory()->agent([$this->billing->id])->unavailable()->create();
        $match = loadAgent(User::factory()->agent([$this->billing->id])->create(), 3);

        return [$this->billing, $match];
    }],
    'admins in the category are not agents' => [function (): array {
        User::factory()->admin()->create()->categories()->attach($this->billing->id);
        $match = loadAgent(User::factory()->agent([$this->billing->id])->create(), 2);

        return [$this->billing, $match];
    }],
    'least active load wins' => [function (): array {
        loadAgent(User::factory()->agent([$this->billing->id])->create(['last_assigned_at' => null]), 3);
        $light = loadAgent(User::factory()->agent([$this->billing->id])->create(['last_assigned_at' => $this->now]), 1);

        return [$this->billing, $light];
    }],
    'finished tickets do not count as load' => [function (): array {
        $closer = loadAgent(User::factory()->agent([$this->billing->id])->create(), 1, 10);
        loadAgent(User::factory()->agent([$this->billing->id])->create(), 2);

        return [$this->billing, $closer];
    }],
    'equal load: never-assigned first' => [function (): array {
        loadAgent(User::factory()->agent([$this->billing->id])->create(['last_assigned_at' => $this->now->subHour()]), 1);
        $never = loadAgent(User::factory()->agent([$this->billing->id])->create(['last_assigned_at' => null]), 1);

        return [$this->billing, $never];
    }],
    'equal load: oldest last assignment first' => [function (): array {
        loadAgent(User::factory()->agent([$this->billing->id])->create(['last_assigned_at' => $this->now->subHour()]), 1);
        $oldest = loadAgent(User::factory()->agent([$this->billing->id])->create(['last_assigned_at' => $this->now->subDays(3)]), 1);

        return [$this->billing, $oldest];
    }],
    'full tie: lowest id' => [function (): array {
        $first = User::factory()->agent([$this->billing->id])->create(['last_assigned_at' => $this->now->subDay()]);
        User::factory()->agent([$this->billing->id])->create(['last_assigned_at' => $this->now->subDay()]);

        return [$this->billing, $first];
    }],
    'no eligible agent' => [function (): array {
        User::factory()->agent([$this->technical->id])->create();

        return [$this->billing, null];
    }],
]);

it('selects the agent required by TD-050', function (Closure $scenario): void {
    [$category, $expected] = $scenario->call($this);
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['category_id' => $category->id, 'assigned_agent_id' => null]);

    expect(app(AgentSelector::class)->selectFor($ticket)?->id)->toBe($expected?->id);
})->with('selection rules');

it('locks the candidate agents FOR UPDATE so concurrent auto-assignments serialize', function (): void {
    $light = User::factory()->agent([$this->billing->id])->create();
    loadAgent(User::factory()->agent([$this->billing->id])->create(), 2);
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['category_id' => $this->billing->id, 'assigned_agent_id' => null]);

    DB::enableQueryLog();
    $selected = DB::transaction(fn () => app(AgentSelector::class)->selectFor($ticket, true));
    $sql = strtolower(collect(DB::getQueryLog())->pluck('query')->implode("\n"));
    DB::disableQueryLog();

    expect($selected?->id)->toBe($light->id)
        ->and($sql)->toContain('for update');
});
