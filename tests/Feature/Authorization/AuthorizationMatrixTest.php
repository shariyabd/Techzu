<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Holiday;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketAssignedNotification;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\AuthorizationMatrix;
use Tests\Support\Imports\ImportCsvFixture;
use Tests\Support\TicketRelation;

beforeEach(function (): void {
    Gate::define('matrix.view', static fn (User $user, Ticket $ticket): Response => $ticket->customer_id === $user->id
        ? Response::allow()
        : Response::denyAsNotFound());

    $show = static function (Ticket $ticket) {
        Gate::authorize('matrix.view', $ticket);

        return response()->json(['number' => $ticket->number]);
    };

    Route::middleware(['web', 'auth'])->get('/_matrix/tickets/{ticket}', $show);
    Route::middleware(['api', 'auth:sanctum'])->get('/api/_matrix/tickets/{ticket}', $show);
});

it('creates a user with the requested role', function (Role $role): void {
    $user = AuthorizationMatrix::for($this)->actingAsRole($role);

    expect($user->exists)->toBeTrue()
        ->and($user->getRoleNames()->all())->toBe([$role->value]);
})->with(AuthorizationMatrix::roles());

it('builds tickets for every relation', function (): void {
    $matrix = AuthorizationMatrix::for($this);
    $customer = $matrix->actingAsRole(Role::Customer);

    $own = $matrix->ticketFor(TicketRelation::Own, ['status' => TicketStatus::Resolved]);
    expect($own->customer_id)->toBe($customer->id)
        ->and($own->status)->toBe(TicketStatus::Resolved);

    $agent = $matrix->actingAsRole(Role::Agent);
    $assigned = $matrix->ticketFor(TicketRelation::Assigned);
    $unassigned = $matrix->ticketFor(TicketRelation::Unassigned);
    $others = $matrix->ticketFor(TicketRelation::Others);

    expect($assigned->assigned_agent_id)->toBe($agent->id)
        ->and($unassigned->assigned_agent_id)->toBeNull()
        ->and($unassigned->customer_id)->not->toBe($agent->id)
        ->and($others->assigned_agent_id)->not->toBeNull()
        ->and($others->assigned_agent_id)->not->toBe($agent->id)
        ->and($others->customer_id)->not->toBe($agent->id);
});

it('labels dataset rows and rejects duplicates', function (): void {
    $row = [Role::Customer, TicketRelation::Own, TicketStatus::Resolved, 'changeStatus', [TicketStatus::Closed], 200];

    expect(array_keys(AuthorizationMatrix::ticketCases([$row])))
        ->toBe(['customer · own · resolved · changeStatus(closed) → 200'])
        ->and(fn () => AuthorizationMatrix::ticketCases([$row, $row]))
        ->toThrow(LogicException::class, 'Duplicate authorization case');
});

it('checks web routes through the web guard', function (): void {
    $matrix = AuthorizationMatrix::for($this);
    $matrix->actingAsRole(Role::Customer);
    $own = $matrix->ticketFor(TicketRelation::Own);
    $other = $matrix->ticketFor(TicketRelation::Others);

    $matrix->expectAccess('GET', "/_matrix/tickets/{$own->number}", 200)->assertJson(['number' => $own->number]);
    $matrix->expectAccess('GET', "/_matrix/tickets/{$other->number}", 404);
});

it('checks api routes through sanctum as JSON', function (): void {
    $matrix = AuthorizationMatrix::for($this);
    $matrix->actingAsRole(Role::Customer);
    $own = $matrix->ticketFor(TicketRelation::Own);

    $matrix->expectAccess('GET', "/api/_matrix/tickets/{$own->number}", 200)->assertJson(['number' => $own->number]);
});

it('builds the ticket for a URI closure via the ownership hook', function (): void {
    AuthorizationMatrix::for($this)->expectAccessAs(
        Role::Customer,
        'GET',
        static fn (Ticket $ticket): string => "/_matrix/tickets/{$ticket->number}",
        404,
        relation: TicketRelation::Others,
    );
});

it('sends guests through unauthenticated', function (): void {
    $ticket = Ticket::factory()->create();
    $matrix = AuthorizationMatrix::for($this)->asGuest();

    $matrix->expectAccess('GET', "/_matrix/tickets/{$ticket->number}", 302);
    $matrix->expectAccess('GET', "/api/_matrix/tickets/{$ticket->number}", 401);
});

dataset('user administration routes', [
    'web users index' => ['GET', 'admin.users.index', false, 200],
    'web users create' => ['GET', 'admin.users.create', false, 200],
    'web users store' => ['POST', 'admin.users.store', false, 302],
    'web users edit' => ['GET', 'admin.users.edit', true, 200],
    'web users update' => ['PATCH', 'admin.users.update', true, 302],
    'web roles index' => ['GET', 'admin.roles.index', false, 200],
    'api users index' => ['GET', 'api.v1.admin.users.index', false, 200],
    'api users store' => ['POST', 'api.v1.admin.users.store', false, 201],
    'api users update' => ['PATCH', 'api.v1.admin.users.update', true, 200],
    'api roles index' => ['GET', 'api.v1.admin.roles.index', false, 200],
]);

it('lets only admins manage users and read the permission matrix', function (string $method, string $name, bool $needsUser, int $adminStatus, Role $role): void {
    $matrix = AuthorizationMatrix::for($this);
    $matrix->actingAsRole($role);
    $target = User::factory()->customer()->create();
    $uri = $needsUser ? route($name, $target) : route($name);
    $payload = $method === 'GET' ? [] : [
        'name' => 'Matrix User',
        'email' => 'matrix.user@example.com',
        'password' => 'password',
        'role' => Role::Customer->value,
    ];

    $matrix->expectAccess($method, $uri, $role === Role::Admin ? $adminStatus : 403, $payload);
})->with('user administration routes')->with([Role::Admin, Role::Agent, Role::Customer]);

it('sends signed-in users away from the password reset screens', function (string $method, string $uri, Role $role): void {
    $matrix = AuthorizationMatrix::for($this);
    $matrix->actingAsRole($role);

    $matrix->expectAccess($method, $uri, 302)->assertRedirect(route('home'));
})->with([
    'forgot form' => ['GET', '/forgot-password'],
    'send link' => ['POST', '/forgot-password'],
    'reset form' => ['GET', '/reset-password/some-token'],
    'reset' => ['POST', '/reset-password'],
])->with([Role::Admin, Role::Customer]);

dataset('p4 routes', function (): array {
    $everyone = fn (int $status, int $guest): array => ['admin' => $status, 'agent' => $status, 'customer' => $status, 'guest' => $guest];
    $staff = fn (int $status, int $guest): array => ['admin' => $status, 'agent' => $status, 'customer' => 403, 'guest' => $guest];
    $admin = fn (int $status, int $guest): array => ['admin' => $status, 'agent' => 403, 'customer' => 403, 'guest' => $guest];
    $routes = [
        ['GET', '/notifications', $everyone(200, 302)],
        ['POST', '/notifications/{notification}/read', $everyone(302, 302)],
        ['POST', '/notifications/read-all', $everyone(302, 302)],
        ['GET', '/api/v1/notifications', $everyone(200, 401)],
        ['POST', '/api/v1/notifications/{notification}/read', $everyone(200, 401)],
        ['POST', '/api/v1/notifications/read-all', $everyone(204, 401)],
        ['GET', '/dashboard', $staff(200, 302)],
        ['GET', '/api/v1/dashboard', $staff(200, 401)],
        ['GET', '/admin/imports', $admin(200, 302)],
        ['GET', '/admin/imports/create', $admin(200, 302)],
        ['POST', '/admin/imports', $admin(302, 302)],
        ['GET', '/admin/imports/{import}', $admin(200, 302)],
        ['GET', '/admin/imports/{import}/errors/download', $admin(200, 302)],
        ['POST', '/api/v1/imports', $admin(422, 401)],
        ['GET', '/api/v1/imports/{import}', $admin(200, 401)],
        ['GET', '/api/v1/imports/{import}/errors', $admin(200, 401)],
        ['GET', '/admin/categories', $admin(200, 302)],
        ['GET', '/admin/categories/create', $admin(200, 302)],
        ['POST', '/admin/categories', $admin(302, 302)],
        ['GET', '/admin/categories/{category}/edit', $admin(200, 302)],
        ['PATCH', '/admin/categories/{category}', $admin(302, 302)],
        ['DELETE', '/admin/categories/{category}', $admin(302, 302)],
        ['GET', '/api/v1/categories', $everyone(200, 401)],
        ['GET', '/api/v1/admin/categories', $admin(200, 401)],
        ['POST', '/api/v1/admin/categories', $admin(422, 401)],
        ['PATCH', '/api/v1/admin/categories/{category}', $admin(422, 401)],
        ['DELETE', '/api/v1/admin/categories/{category}', $admin(204, 401)],
        ['GET', '/admin/holidays', $admin(200, 302)],
        ['GET', '/admin/holidays/create', $admin(200, 302)],
        ['POST', '/admin/holidays', $admin(302, 302)],
        ['GET', '/admin/holidays/{holiday}/edit', $admin(200, 302)],
        ['PATCH', '/admin/holidays/{holiday}', $admin(302, 302)],
        ['DELETE', '/admin/holidays/{holiday}', $admin(302, 302)],
        ['GET', '/api/v1/admin/holidays', $admin(200, 401)],
        ['POST', '/api/v1/admin/holidays', $admin(422, 401)],
        ['PATCH', '/api/v1/admin/holidays/{holiday}', $admin(422, 401)],
        ['DELETE', '/api/v1/admin/holidays/{holiday}', $admin(204, 401)],
    ];
    $cases = [];
    foreach ($routes as [$method, $uri, $statuses]) {
        foreach ($statuses as $who => $status) {
            $cases["{$who} · {$method} {$uri} → {$status}"] = [$who, $method, $uri, $status];
        }
    }

    return $cases;
});

it('authorizes every P4 route', function (string $who, string $method, string $uri, int $status): void {
    $this->withoutVite();
    $matrix = AuthorizationMatrix::for($this);
    $user = $who === 'guest' ? null : $matrix->actingAsRole(Role::from($who));
    if ($user === null) {
        $matrix->asGuest();
    }
    $owner = $user ?? User::factory()->customer()->create();
    $placeholders = [
        '{notification}' => (string) $owner->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => TicketAssignedNotification::class,
            'data' => ['ticket_number' => null, 'title' => 't', 'body' => 'b', 'url' => url('/')],
        ])->id,
        '{import}' => (string) ImportCsvFixture::pendingImport(User::factory()->admin()->create())->id,
        '{category}' => (string) Category::factory()->create()->id,
        '{holiday}' => (string) Holiday::factory()->create()->id,
    ];
    $response = $matrix->expectAccess($method, strtr($uri, $placeholders), $status);
    if ($who === 'guest' && $status === 302) {
        $response->assertRedirect(route('login'));
    }
})->with('p4 routes');
