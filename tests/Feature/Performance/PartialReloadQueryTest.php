<?php

declare(strict_types=1);

use App\Enums\TicketStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

const PARTIAL_RELOAD_AGENT_SQL = 'order by `users`.`name` asc limit 500';
const PARTIAL_RELOAD_CATEGORY_SQL = 'order by `name` asc limit 500';

/** @return list<string> */
function loggedQueries(Closure $visit): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $visit();
    $queries = array_map(static fn (array $query): string => $query['query'], DB::getQueryLog());
    DB::disableQueryLog();

    return $queries;
}

/** @return array<string, string> */
function partialReloadHeaders(string $component, string $only): array
{
    return [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/')),
        'X-Inertia-Partial-Component' => $component,
        'X-Inertia-Partial-Data' => $only,
    ];
}

function runsListQuery(array $queries, string $sql): bool
{
    return collect($queries)->contains(static fn (string $query): bool => str_contains($query, $sql));
}

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->ticket = Ticket::factory()->status(TicketStatus::Open)->assignedTo(User::factory()->agent()->create())->create();
    $this->withoutVite()->actingAs($this->admin);
});

it('skips the filter option lists when the ticket index reloads only its rows', function (): void {
    $full = loggedQueries(fn () => $this->get(route('tickets.index'))->assertOk());
    $partial = loggedQueries(fn () => $this->get(route('tickets.index'), partialReloadHeaders('tickets/Index', 'tickets'))
        ->assertOk()
        ->assertJsonPath('props.tickets.data.0.number', $this->ticket->number)
        ->assertJsonMissingPath('props.filterOptions')
        ->assertJsonMissingPath('props.queues'));

    expect(runsListQuery($full, PARTIAL_RELOAD_AGENT_SQL))->toBeTrue()
        ->and(runsListQuery($full, PARTIAL_RELOAD_CATEGORY_SQL))->toBeTrue()
        ->and(runsListQuery($partial, PARTIAL_RELOAD_AGENT_SQL))->toBeFalse()
        ->and(runsListQuery($partial, PARTIAL_RELOAD_CATEGORY_SQL))->toBeFalse();
});

it('skips the agent and form option lists when the ticket page reloads after a realtime change', function (): void {
    $only = 'ticket,replies,repliesHasMore,attachments,activities,activitiesHasMore,can';
    $full = loggedQueries(fn () => $this->get(route('tickets.show', $this->ticket))->assertOk());
    $partial = loggedQueries(fn () => $this->get(route('tickets.show', $this->ticket), partialReloadHeaders('tickets/Show', $only))
        ->assertOk()
        ->assertJsonPath('props.can.assign', true)
        ->assertJsonPath('props.ticket.can.assign', true)
        ->assertJsonMissingPath('props.agents')
        ->assertJsonMissingPath('props.options'));

    expect(runsListQuery($full, PARTIAL_RELOAD_AGENT_SQL))->toBeTrue()
        ->and(runsListQuery($full, PARTIAL_RELOAD_CATEGORY_SQL))->toBeTrue()
        ->and(runsListQuery($partial, PARTIAL_RELOAD_AGENT_SQL))->toBeFalse()
        ->and(runsListQuery($partial, PARTIAL_RELOAD_CATEGORY_SQL))->toBeFalse();
});
