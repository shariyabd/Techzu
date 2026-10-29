<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\Support\AuthorizationMatrix;
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
