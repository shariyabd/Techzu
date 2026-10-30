<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Tests\Support\AuthorizationMatrix;

dataset('assignment route matrix', function (): array {
    $rows = [
        ['customer', 'own', 'claim', 403, 403],
        ['customer', 'own', 'assign', 403, 403],
        ['customer', 'other', 'claim', 404, 404],
        ['customer', 'other', 'assign', 404, 404],
        ['agent', 'unassigned', 'claim', 302, 200],
        ['agent', 'unassigned', 'assign', 403, 403],
        ['agent', 'colleague', 'claim', 302, 409],
        ['agent', 'colleague', 'assign', 403, 403],
        ['agent', 'own', 'assign', 302, 422],
        ['agent', 'resolved', 'claim', 403, 403],
        ['admin', 'unassigned', 'claim', 302, 200],
        ['admin', 'unassigned', 'assign', 302, 422],
        ['admin', 'colleague', 'assign', 302, 422],
        ['admin', 'resolved', 'assign', 403, 403],
    ];
    $cases = [];
    foreach ($rows as [$role, $ownership, $action, $web, $api]) {
        $cases["web {$role} {$ownership} {$action}"] = [$role, $ownership, "/tickets/{n}/{$action}", $web];
        $cases["api {$role} {$ownership} {$action}"] = [$role, $ownership, "/api/v1/tickets/{n}/{$action}", $api];
    }

    return $cases;
});

it('enforces the assignment matrix', function (string $role, string $ownership, string $uri, int $status): void {
    $matrix = AuthorizationMatrix::for($this);
    $user = $matrix->actingAsRole(Role::from($role));
    $factory = Ticket::factory()->status(TicketStatus::Open);
    $ticket = match ($ownership) {
        'own' => $role === 'customer'
            ? $factory->create(['customer_id' => $user->id, 'created_by_id' => $user->id, 'assigned_agent_id' => null])
            : $factory->assignedTo($user)->create(),
        'colleague' => $factory->assignedTo(User::factory()->agent()->create())->create(),
        'resolved' => Ticket::factory()->status(TicketStatus::Resolved)->create(['assigned_agent_id' => null]),
        'unassigned', 'other' => $factory->create(['assigned_agent_id' => null]),
    };
    $matrix->expectAccess('POST', str_replace('{n}', $ticket->number, $uri), $status);
})->with('assignment route matrix');

it('requires authentication on the assignment routes', function (string $uri, int $status): void {
    $ticket = Ticket::factory()->create();
    $uri = str_replace('{n}', $ticket->number, $uri);
    (str_starts_with($uri, '/api/') ? $this->postJson($uri) : $this->post($uri))->assertStatus($status);
})->with([
    ['/tickets/{n}/claim', 302],
    ['/tickets/{n}/assign', 302],
    ['/api/v1/tickets/{n}/claim', 401],
    ['/api/v1/tickets/{n}/assign', 401],
]);
