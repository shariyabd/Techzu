<?php

declare(strict_types=1);

use App\Actions\Admin\ChangeUserRole;
use App\Actions\Admin\SyncAgentProfile;
use App\Actions\Conversation\AddReply;
use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Services\Agents\AgentDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\Support\AuthorizationMatrix;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-29 06:00:00', 'UTC'));
});

function directoryFilters(array $overrides = []): array
{
    return array_merge([
        'q' => null,
        'role' => AgentDirectory::DEFAULT_ROLE,
        'availability' => null,
        'category_id' => null,
        'sort' => AgentDirectory::DEFAULT_SORT,
        'per_page' => 20,
    ], $overrides);
}

function replyOn(Ticket $ticket, User $author, int $minutesAgo, bool $internal = false): void
{
    TicketReply::factory()->create([
        'ticket_id' => $ticket->id,
        'author_id' => $author->id,
        'is_internal' => $internal,
        'created_at' => CarbonImmutable::now()->subMinutes($minutesAgo),
    ]);
}

function seedAgentWorkload(User $agent): void
{
    $colleague = User::factory()->agent()->create();
    $assigned = Ticket::factory()->assignedTo($agent);

    $assigned->status(TicketStatus::Open)->create();
    $customerFollowUp = $assigned->status(TicketStatus::InProgress)->create();
    replyOn($customerFollowUp, $customerFollowUp->customer()->firstOrFail(), 30);
    replyOn($customerFollowUp, $agent, 10, internal: true);
    $customerAnsweredLast = $assigned->status(TicketStatus::InProgress)->create();
    replyOn($customerAnsweredLast, $agent, 60);
    replyOn($customerAnsweredLast, $customerAnsweredLast->customer()->firstOrFail(), 20);
    $answeredByColleague = $assigned->status(TicketStatus::InProgress)->create();
    replyOn($answeredByColleague, $answeredByColleague->customer()->firstOrFail(), 60);
    replyOn($answeredByColleague, $colleague, 5);
    $assigned->status(TicketStatus::Open)->breached()->create();
    $pausedPastDue = $assigned->status(TicketStatus::WaitingForCustomer)->breached()->create();
    replyOn($pausedPastDue, $agent, 15);
    $assigned->status(TicketStatus::Resolved)->create(['resolved_at' => CarbonImmutable::now()->subDays(2)]);
    $assigned->status(TicketStatus::Closed)->create(['resolved_at' => CarbonImmutable::now()->subDays(40)]);
    Ticket::factory()->assignedTo($colleague)->status(TicketStatus::Open)->breached()->create();
}

it('lets staff open the agent directory and keeps customers and guests out', function (string $who, string $route, int $status): void {
    $this->withoutVite();
    $matrix = AuthorizationMatrix::for($this);
    $target = User::factory()->agent()->create();

    if ($who === 'guest') {
        $matrix->asGuest();
    } else {
        $matrix->actingAsRole(Role::from($who));
    }

    $response = $matrix->expectAccess('GET', route($route, str_ends_with($route, 'show') ? $target : []), $status);

    if ($status === 302) {
        $response->assertRedirect(route('login'));
    }
})->with([
    'admin · web index' => ['admin', 'agents.index', 200],
    'agent · web index' => ['agent', 'agents.index', 200],
    'customer · web index' => ['customer', 'agents.index', 403],
    'guest · web index' => ['guest', 'agents.index', 302],
    'admin · web show' => ['admin', 'agents.show', 200],
    'agent · web show' => ['agent', 'agents.show', 200],
    'customer · web show' => ['customer', 'agents.show', 403],
    'guest · web show' => ['guest', 'agents.show', 302],
    'admin · api index' => ['admin', 'api.v1.agents.index', 200],
    'agent · api index' => ['agent', 'api.v1.agents.index', 200],
    'customer · api index' => ['customer', 'api.v1.agents.index', 403],
    'guest · api index' => ['guest', 'api.v1.agents.index', 401],
    'admin · api show' => ['admin', 'api.v1.agents.show', 200],
    'agent · api show' => ['agent', 'api.v1.agents.show', 200],
    'customer · api show' => ['customer', 'api.v1.agents.show', 403],
    'guest · api show' => ['guest', 'api.v1.agents.show', 401],
]);

it('answers 404 for a profile of someone who is not staff', function (string $route): void {
    $customer = User::factory()->customer()->create();

    AuthorizationMatrix::for($this)->expectAccessAs(Role::Agent, 'GET', route($route, $customer), 404);
})->with(['agents.show', 'api.v1.agents.show']);

it('counts unresponded, awaiting reply, breached and recently resolved tickets per agent', function (): void {
    $agent = User::factory()->agent()->create();
    seedAgentWorkload($agent);

    $directory = app(AgentDirectory::class);
    $profile = $directory->profile($agent, CarbonImmutable::now());

    expect(collect(AgentDirectory::STATS)->mapWithKeys(fn (string $stat): array => [$stat => (int) $profile->getAttribute($stat)])->all())
        ->toBe([
            'total_assigned_count' => 8,
            'active_count' => 6,
            'unresponded_count' => 3,
            'awaiting_reply_count' => 2,
            'breached_count' => 1,
            'resolved_recent_count' => 1,
        ])
        ->and($directory->summary(CarbonImmutable::now()))->toBe([
            'agents' => 2,
            'available' => 2,
            'unresponded' => 4,
            'breached' => 2,
        ]);
});

it('refreshes the cached summary and workload sort when agent profiles or responses change', function (Closure $change, array $expectedSummary, array $expectedOrder): void {
    $admin = User::factory()->admin()->create();
    $quiet = User::factory()->agent()->create(['name' => 'Able']);
    $busy = User::factory()->agent()->create(['name' => 'Busy']);
    $ticket = Ticket::factory()->assignedTo($busy)->status(TicketStatus::InProgress)->create();
    $directory = app(AgentDirectory::class);
    $order = fn (): array => collect($directory->paginate(directoryFilters(['sort' => '-unresponded']), CarbonImmutable::now())->items())->pluck('name')->all();

    expect($directory->summary(CarbonImmutable::now()))->toBe(['agents' => 2, 'available' => 2, 'unresponded' => 1, 'breached' => 0])
        ->and($order())->toBe(['Busy', 'Able']);

    $change($admin, $quiet, $busy, $ticket);

    expect($directory->summary(CarbonImmutable::now()))->toBe($expectedSummary)
        ->and($order())->toBe($expectedOrder);
})->with([
    'availability turned off' => [
        fn (User $admin, User $quiet) => app(SyncAgentProfile::class)->handle($admin, $quiet, [], false),
        ['agents' => 2, 'available' => 1, 'unresponded' => 1, 'breached' => 0],
        ['Busy', 'Able'],
    ],
    'agent demoted to customer' => [
        fn (User $admin, User $quiet) => app(ChangeUserRole::class)->handle($admin, $quiet, Role::Customer),
        ['agents' => 1, 'available' => 1, 'unresponded' => 1, 'breached' => 0],
        ['Busy'],
    ],
    'first staff response' => [
        fn (User $admin, User $quiet, User $busy, Ticket $ticket) => app(AddReply::class)->handle($busy, $ticket, 'On it.', false),
        ['agents' => 2, 'available' => 2, 'unresponded' => 0, 'breached' => 0],
        ['Able', 'Busy'],
    ],
]);

it('filters by search, availability, category and role', function (array $filters, array $expected): void {
    $support = Category::factory()->create();
    User::factory()->agent([$support->id])->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.test']);
    User::factory()->agent()->unavailable()->create(['name' => 'Brian Kernighan', 'email' => 'bk@example.test']);
    User::factory()->admin()->create(['name' => 'Carol Admin', 'email' => 'carol@example.test']);
    User::factory()->customer()->create(['name' => 'Ada Customer', 'email' => 'ada.customer@example.test']);

    if (($filters['category_id'] ?? null) === 'support') {
        $filters['category_id'] = $support->id;
    }

    $names = collect(app(AgentDirectory::class)->paginate(directoryFilters($filters), CarbonImmutable::now())->items())
        ->pluck('name')
        ->all();

    expect($names)->toBe($expected);
})->with([
    'agents by default' => [[], ['Ada Lovelace', 'Brian Kernighan']],
    'name prefix' => [['q' => 'ada'], ['Ada Lovelace']],
    'email prefix' => [['q' => 'bk@'], ['Brian Kernighan']],
    'available only' => [['availability' => 'available'], ['Ada Lovelace']],
    'unavailable only' => [['availability' => 'unavailable'], ['Brian Kernighan']],
    'category' => [['category_id' => 'support'], ['Ada Lovelace']],
    'admins' => [['role' => 'admin'], ['Carol Admin']],
    'all staff' => [['role' => 'all'], ['Ada Lovelace', 'Brian Kernighan', 'Carol Admin']],
]);

it('sorts by workload columns from the whitelist', function (string $sort, array $expected): void {
    User::factory()->agent()->create(['name' => 'Quiet']);
    $busy = User::factory()->agent()->create(['name' => 'Busy']);
    $late = User::factory()->agent()->create(['name' => 'Late']);
    Ticket::factory()->count(3)->assignedTo($busy)->status(TicketStatus::InProgress)->create();
    replyOn(Ticket::factory()->assignedTo($busy)->status(TicketStatus::InProgress)->create(), $busy, 5);
    Ticket::factory()->count(2)->assignedTo($late)->status(TicketStatus::Open)->breached()->create();

    $names = collect(app(AgentDirectory::class)->paginate(directoryFilters(['sort' => $sort]), CarbonImmutable::now())->items())
        ->pluck('name')
        ->all();

    expect($names)->toBe($expected);
})->with([
    'name' => ['name', ['Busy', 'Late', 'Quiet']],
    'most active' => ['-active', ['Busy', 'Late', 'Quiet']],
    'most unresponded' => ['-unresponded', ['Busy', 'Late', 'Quiet']],
    'most breached' => ['-breached', ['Late', 'Busy', 'Quiet']],
]);

it('rejects a sort or filter outside the whitelist', function (array $query): void {
    Sanctum::actingAs(User::factory()->agent()->create(), ['*']);

    $this->getJson(route('api.v1.agents.index', $query))->assertUnprocessable();
})->with([
    'raw column sort' => [['sort' => 'users.password']],
    'unknown role' => [['role' => 'customer']],
    'unknown availability' => [['availability' => 'busy']],
    'oversized page' => [['per_page' => 101]],
]);

it('serves the directory rows and summary through the web page and the API', function (): void {
    $agent = User::factory()->agent()->create(['name' => 'Agent Alpha']);
    Ticket::factory()->assignedTo($agent)->status(TicketStatus::Open)->breached()->create();
    $viewer = User::factory()->admin()->create();

    $this->withoutVite()->actingAs($viewer)->get(route('agents.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('agents/Index', false)
            ->where('agents.data.0.name', 'Agent Alpha')
            ->where('agents.data.0.stats.breached', 1)
            ->where('summary.breached', 1)
            ->has('options.sorts', count(AgentDirectory::SORTS)));

    $this->withoutVite()->get(route('agents.show', [$agent, 'tab' => 'breached']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('agents/Show', false)
            ->where('agents.data.0.id', $agent->id)
            ->where('agents.has_more', false)
            ->where('agent.stats.unresponded', 1)
            ->where('tab', 'breached')
            ->has('tickets.data', 1)
            ->missing('summary'));

    Sanctum::actingAs($viewer, ['*']);
    $this->getJson(route('api.v1.agents.index'))
        ->assertOk()
        ->assertJsonPath('summary.agents', 1)
        ->assertJsonPath('data.0.id', $agent->id)
        ->assertJsonStructure(['data' => [['id', 'name', 'email', 'avatar_url', 'role', 'is_available', 'last_seen_at', 'categories', 'stats' => ['total_assigned', 'active', 'unresponded', 'awaiting_reply', 'breached', 'resolved_recent']]], 'meta', 'links', 'summary']);
});

it('aggregates the full ticket history only for the agents on the current page', function (): void {
    $ada = User::factory()->agent()->create(['name' => 'Ada']);
    $bea = User::factory()->agent()->create(['name' => 'Bea']);
    $cyd = User::factory()->agent()->create(['name' => 'Cyd']);
    foreach ([$ada, $bea, $cyd] as $agent) {
        Ticket::factory()->assignedTo($agent)->status(TicketStatus::Closed)->create();
        Ticket::factory()->assignedTo($agent)->status(TicketStatus::Open)->create();
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $page = app(AgentDirectory::class)->paginate(directoryFilters(['per_page' => 2]), CarbonImmutable::now());
    $history = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'total_assigned_count'));
    DB::disableQueryLog();

    expect(collect($page->items())->map(fn (User $user): array => [$user->name, (int) $user->getAttribute('total_assigned_count')])->all())
        ->toBe([['Ada', 2], ['Bea', 2]])
        ->and($history)->toHaveCount(1)
        ->and($history->first()['query'])->toContain('`tickets`.`assigned_agent_id` in (?, ?)')
        ->and($history->first()['bindings'])->toContain($ada->id, $bea->id)
        ->and($history->first()['bindings'])->not->toContain($cyd->id);
});

it('lists exactly the tickets each profile tab counts', function (string $tab, string $stat): void {
    $agent = User::factory()->agent()->create();
    $viewer = User::factory()->agent()->create();
    seedAgentWorkload($agent);
    $directory = app(AgentDirectory::class);
    $now = CarbonImmutable::now();

    expect($directory->tickets($viewer, $agent, $tab, $now)->total())
        ->toBe((int) $directory->profile($agent, $now)->getAttribute($stat));
})->with([
    'active' => ['active', 'active_count'],
    'unresponded' => ['unresponded', 'unresponded_count'],
    'awaiting reply' => ['awaiting', 'awaiting_reply_count'],
    'SLA breached' => ['breached', 'breached_count'],
    'resolved in the window' => ['resolved', 'resolved_recent_count'],
    'all assigned' => ['all', 'total_assigned_count'],
]);

it('previews the latest public message and never an internal note', function (): void {
    $agent = User::factory()->agent()->create();
    $answered = Ticket::factory()->assignedTo($agent)->status(TicketStatus::InProgress)->create(['updated_at' => CarbonImmutable::now()->subMinute()]);
    TicketReply::factory()->create(['ticket_id' => $answered->id, 'author_id' => $agent->id, 'body' => "Public\nanswer", 'created_at' => CarbonImmutable::now()->subMinutes(30)]);
    TicketReply::factory()->create(['ticket_id' => $answered->id, 'author_id' => $agent->id, 'body' => 'Secret note', 'is_internal' => true, 'created_at' => CarbonImmutable::now()->subMinutes(5)]);
    $noted = Ticket::factory()->assignedTo($agent)->status(TicketStatus::Open)->create(['description' => 'Opening message', 'updated_at' => CarbonImmutable::now()->subMinutes(2)]);
    TicketReply::factory()->create(['ticket_id' => $noted->id, 'author_id' => $agent->id, 'body' => 'Another secret', 'is_internal' => true]);

    $this->withoutVite()->actingAs(User::factory()->admin()->create())
        ->get(route('agents.show', $agent))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('tickets.data.0.number', $answered->number)
            ->where('tickets.data.0.latest_message', 'Public answer')
            ->where('tickets.data.1.number', $noted->number)
            ->where('tickets.data.1.latest_message', 'Opening message'))
        ->assertDontSee('Secret note')
        ->assertDontSee('Another secret');
});

it('rejects a profile tab outside the whitelist', function (string $tab): void {
    $agent = User::factory()->agent()->create();

    $this->withoutVite()->actingAs(User::factory()->admin()->create())
        ->get(route('agents.show', [$agent, 'tab' => $tab]))
        ->assertSessionHasErrors('tab');
})->with(['unknown tab' => 'archived', 'raw column' => 'tickets.status']);
