<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\TicketReply;
use Illuminate\Support\Facades\Gate;
use Tests\Support\AuthorizationMatrix;
use Tests\Support\TicketRelation;

it('hides internal replies and invisible tickets from customers', function (Role $role, TicketRelation $relation, bool $internal, int $expected): void {
    $matrix = AuthorizationMatrix::for($this);
    $user = $matrix->actingAsRole($role);
    $ticket = $matrix->ticketFor($relation);
    $factory = TicketReply::factory()->for($ticket);
    $reply = ($internal ? $factory->internal() : $factory)->create();

    expect(AuthorizationMatrix::statusOf(Gate::forUser($user)->inspect('view', $reply)))->toBe($expected);
})->with([
    'customer · own ticket · public reply → 200' => [Role::Customer, TicketRelation::Own, false, 200],
    'customer · own ticket · internal note → 404' => [Role::Customer, TicketRelation::Own, true, 404],
    'customer · other ticket · public reply → 404' => [Role::Customer, TicketRelation::Others, false, 404],
    'customer · other ticket · internal note → 404' => [Role::Customer, TicketRelation::Others, true, 404],
    'agent · colleague ticket · public reply → 200' => [Role::Agent, TicketRelation::Others, false, 200],
    'agent · colleague ticket · internal note → 200' => [Role::Agent, TicketRelation::Others, true, 200],
    'agent · unassigned ticket · internal note → 200' => [Role::Agent, TicketRelation::Unassigned, true, 200],
    'admin · any ticket · internal note → 200' => [Role::Admin, TicketRelation::Others, true, 200],
]);
