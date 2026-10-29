<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Attachment;
use Illuminate\Support\Facades\Gate;
use Tests\Support\AuthorizationMatrix;
use Tests\Support\TicketRelation;

it('authorizes downloads by ticket visibility and the internal flag', function (Role $role, TicketRelation $relation, bool $internal, int $expected): void {
    $matrix = AuthorizationMatrix::for($this);
    $user = $matrix->actingAsRole($role);
    $ticket = $matrix->ticketFor($relation);
    $attachment = Attachment::factory()->for($ticket)->create(['reply_id' => null, 'is_internal' => $internal]);

    expect(AuthorizationMatrix::statusOf(Gate::forUser($user)->inspect('view', $attachment)))->toBe($expected);
})->with([
    'customer · own ticket · public file → 200' => [Role::Customer, TicketRelation::Own, false, 200],
    'customer · own ticket · internal file → 404' => [Role::Customer, TicketRelation::Own, true, 404],
    'customer · other ticket · public file → 404' => [Role::Customer, TicketRelation::Others, false, 404],
    'customer · other ticket · internal file → 404' => [Role::Customer, TicketRelation::Others, true, 404],
    'agent · colleague ticket · public file → 200' => [Role::Agent, TicketRelation::Others, false, 200],
    'agent · colleague ticket · internal file → 200' => [Role::Agent, TicketRelation::Others, true, 200],
    'admin · any ticket · internal file → 200' => [Role::Admin, TicketRelation::Others, true, 200],
]);
