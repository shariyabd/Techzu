<?php

declare(strict_types=1);

use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function seedDirectoryAgents(int $count, Category $category): void
{
    for ($i = 0; $i < $count; $i++) {
        $agent = User::factory()->agent([$category->id])->create();
        $ticket = Ticket::factory()->assignedTo($agent)->status(TicketStatus::InProgress)->breached()->create(['category_id' => $category->id]);
        TicketReply::factory()->create(['ticket_id' => $ticket->id, 'author_id' => $agent->id]);
        Ticket::factory()->assignedTo($agent)->status(TicketStatus::Resolved)->create(['category_id' => $category->id]);
    }
}

function directoryQueryCount(Closure $visit): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $visit();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('keeps the agent directory at a constant query count for 1 versus 15 agents', function (string $routeName): void {
    $viewer = User::factory()->admin()->create();
    $category = Category::factory()->create();
    $isApi = str_starts_with($routeName, 'api.');

    if ($isApi) {
        Sanctum::actingAs($viewer, ['*']);
    } else {
        $this->withoutVite()->actingAs($viewer);
    }

    $visit = fn () => $isApi
        ? $this->getJson(route($routeName, ['sort' => '-breached']))->assertOk()
        : $this->get(route($routeName, ['sort' => '-breached']))->assertOk();

    seedDirectoryAgents(1, $category);
    $visit();
    $one = directoryQueryCount($visit);

    seedDirectoryAgents(14, $category);
    $fifteen = directoryQueryCount($visit);

    expect($fifteen)->toBe($one);
})->with(['agents.index', 'api.v1.agents.index']);

it('keeps the agent profile page at a constant query count for 1 versus 15 tickets', function (string $tab): void {
    $viewer = User::factory()->admin()->create();
    $category = Category::factory()->create();
    seedDirectoryAgents(3, $category);
    $agent = User::factory()->agent([$category->id])->create();
    $seedTickets = function (int $count) use ($agent, $category): void {
        for ($i = 0; $i < $count; $i++) {
            $ticket = Ticket::factory()->assignedTo($agent)->status(TicketStatus::InProgress)->breached()->create(['category_id' => $category->id]);
            TicketReply::factory()->create(['ticket_id' => $ticket->id, 'author_id' => $ticket->customer_id]);
            TicketReply::factory()->create(['ticket_id' => $ticket->id, 'author_id' => $agent->id, 'is_internal' => true]);
        }
    };
    $this->withoutVite()->actingAs($viewer);
    $visit = fn () => $this->get(route('agents.show', [$agent, 'tab' => $tab]))->assertOk();

    $seedTickets(1);
    $visit();
    $one = directoryQueryCount($visit);

    $seedTickets(14);
    $fifteen = directoryQueryCount($visit);

    expect($fifteen)->toBe($one);
})->with(['active', 'breached', 'all']);
