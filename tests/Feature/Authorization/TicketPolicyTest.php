<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Support\Facades\Gate;
use Tests\Support\AuthorizationMatrix;
use Tests\Support\TicketRelation;

$customer = Role::Customer;
$agent = Role::Agent;
$admin = Role::Admin;
$own = TicketRelation::Own;
$assigned = TicketRelation::Assigned;
$unassigned = TicketRelation::Unassigned;
$others = TicketRelation::Others;
$open = TicketStatus::Open;
$inProgress = TicketStatus::InProgress;
$waiting = TicketStatus::WaitingForCustomer;
$resolved = TicketStatus::Resolved;
$closed = TicketStatus::Closed;

$rows = [
    [$customer, $own, $open, 'view', [], 200],
    [$customer, $own, $open, 'update', [], 200],
    [$customer, $own, $open, 'changeStatus', [], 403],
    [$customer, $own, $open, 'changePriority', [], 403],
    [$customer, $own, $open, 'claim', [], 403],
    [$customer, $own, $open, 'assign', [], 403],
    [$customer, $own, $open, 'reply', [], 200],
    [$customer, $own, $open, 'addInternalNote', [], 403],
    [$customer, $own, $open, 'viewInternal', [], 403],
    [$customer, $own, $inProgress, 'update', [], 403],
    [$customer, $own, $inProgress, 'reply', [], 200],
    [$customer, $own, $inProgress, 'changeStatus', [], 403],
    [$customer, $own, $inProgress, 'changeStatus', [$closed], 403],
    [$customer, $own, $waiting, 'update', [], 403],
    [$customer, $own, $waiting, 'reply', [], 200],
    [$customer, $own, $resolved, 'changeStatus', [], 200],
    [$customer, $own, $resolved, 'changeStatus', [$closed], 200],
    [$customer, $own, $resolved, 'changeStatus', [$inProgress], 200],
    [$customer, $own, $resolved, 'changeStatus', [$waiting], 403],
    [$customer, $own, $resolved, 'changeStatus', [$open], 403],
    [$customer, $own, $resolved, 'update', [], 403],
    [$customer, $own, $resolved, 'reply', [], 200],
    [$customer, $own, $closed, 'view', [], 200],
    [$customer, $own, $closed, 'changeStatus', [], 403],
    [$customer, $own, $closed, 'changeStatus', [$inProgress], 403],
    [$customer, $own, $closed, 'reply', [], 403],
    [$customer, $others, $open, 'view', [], 404],
    [$customer, $others, $open, 'update', [], 404],
    [$customer, $others, $open, 'changeStatus', [], 404],
    [$customer, $others, $open, 'changePriority', [], 404],
    [$customer, $others, $open, 'claim', [], 404],
    [$customer, $others, $open, 'assign', [], 404],
    [$customer, $others, $open, 'reply', [], 404],
    [$customer, $others, $open, 'addInternalNote', [], 404],
    [$customer, $others, $open, 'viewInternal', [], 404],
    [$customer, $others, $resolved, 'changeStatus', [$closed], 404],
    [$customer, $unassigned, $open, 'view', [], 404],
    [$customer, $unassigned, $open, 'reply', [], 404],
    [$agent, $assigned, $inProgress, 'view', [], 200],
    [$agent, $assigned, $inProgress, 'update', [], 200],
    [$agent, $assigned, $inProgress, 'changeStatus', [], 200],
    [$agent, $assigned, $inProgress, 'changeStatus', [$resolved], 200],
    [$agent, $assigned, $inProgress, 'changePriority', [], 200],
    [$agent, $assigned, $inProgress, 'claim', [], 200],
    [$agent, $assigned, $inProgress, 'assign', [], 200],
    [$agent, $assigned, $inProgress, 'reply', [], 200],
    [$agent, $assigned, $inProgress, 'addInternalNote', [], 200],
    [$agent, $assigned, $inProgress, 'viewInternal', [], 200],
    [$agent, $assigned, $closed, 'view', [], 200],
    [$agent, $assigned, $closed, 'reply', [], 403],
    [$agent, $assigned, $closed, 'addInternalNote', [], 403],
    [$agent, $assigned, $closed, 'viewInternal', [], 200],
    [$agent, $unassigned, $open, 'view', [], 200],
    [$agent, $unassigned, $open, 'update', [], 403],
    [$agent, $unassigned, $open, 'changeStatus', [], 403],
    [$agent, $unassigned, $open, 'changePriority', [], 403],
    [$agent, $unassigned, $open, 'claim', [], 200],
    [$agent, $unassigned, $open, 'assign', [], 403],
    [$agent, $unassigned, $open, 'reply', [], 200],
    [$agent, $unassigned, $open, 'addInternalNote', [], 200],
    [$agent, $unassigned, $open, 'viewInternal', [], 200],
    [$agent, $unassigned, $resolved, 'claim', [], 403],
    [$agent, $unassigned, $closed, 'claim', [], 403],
    [$agent, $others, $inProgress, 'view', [], 200],
    [$agent, $others, $inProgress, 'update', [], 403],
    [$agent, $others, $inProgress, 'changeStatus', [], 403],
    [$agent, $others, $inProgress, 'changeStatus', [$resolved], 403],
    [$agent, $others, $inProgress, 'changePriority', [], 403],
    [$agent, $others, $inProgress, 'claim', [], 200],
    [$agent, $others, $inProgress, 'assign', [], 403],
    [$agent, $others, $inProgress, 'reply', [], 200],
    [$agent, $others, $inProgress, 'addInternalNote', [], 200],
    [$agent, $others, $inProgress, 'viewInternal', [], 200],
    [$admin, $others, $inProgress, 'view', [], 200],
    [$admin, $others, $inProgress, 'update', [], 200],
    [$admin, $others, $inProgress, 'changeStatus', [], 200],
    [$admin, $others, $inProgress, 'changeStatus', [$resolved], 200],
    [$admin, $others, $inProgress, 'changePriority', [], 200],
    [$admin, $others, $inProgress, 'claim', [], 200],
    [$admin, $others, $inProgress, 'assign', [], 200],
    [$admin, $others, $inProgress, 'reply', [], 200],
    [$admin, $others, $inProgress, 'addInternalNote', [], 200],
    [$admin, $others, $inProgress, 'viewInternal', [], 200],
    [$admin, $unassigned, $open, 'update', [], 200],
    [$admin, $unassigned, $open, 'claim', [], 200],
    [$admin, $unassigned, $open, 'assign', [], 200],
    [$admin, $others, $closed, 'view', [], 200],
    [$admin, $others, $closed, 'reply', [], 403],
    [$admin, $others, $closed, 'addInternalNote', [], 403],
];

it('authorizes class-level ticket abilities by role', function (Role $role, string $ability, int $expected): void {
    $matrix = AuthorizationMatrix::for($this);
    $user = $matrix->actingAsRole($role);

    expect(AuthorizationMatrix::statusOf(Gate::forUser($user)->inspect($ability, Ticket::class)))->toBe($expected);
})->with([
    'customer · viewAny → 200' => [Role::Customer, 'viewAny', 200],
    'agent · viewAny → 200' => [Role::Agent, 'viewAny', 200],
    'admin · viewAny → 200' => [Role::Admin, 'viewAny', 200],
    'customer · create → 200' => [Role::Customer, 'create', 200],
    'agent · create → 200' => [Role::Agent, 'create', 200],
    'admin · create → 200' => [Role::Admin, 'create', 200],
    'customer · createOnBehalf → 403' => [Role::Customer, 'createOnBehalf', 403],
    'agent · createOnBehalf → 200' => [Role::Agent, 'createOnBehalf', 200],
    'admin · createOnBehalf → 200' => [Role::Admin, 'createOnBehalf', 200],
]);

it('authorizes ticket abilities by role, ownership and status', function (
    Role $role,
    TicketRelation $relation,
    TicketStatus $status,
    string $ability,
    array $arguments,
    int $expected,
): void {
    $matrix = AuthorizationMatrix::for($this);
    $user = $matrix->actingAsRole($role);
    $ticket = $matrix->ticketFor($relation, ['status' => $status]);

    $response = Gate::forUser($user)->inspect($ability, [$ticket, ...$arguments]);

    expect(AuthorizationMatrix::statusOf($response))->toBe($expected);
})->with(AuthorizationMatrix::ticketCases($rows));
