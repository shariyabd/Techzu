<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use Tests\Support\AuthorizationMatrix;

dataset('ticket route matrix', function (): array {
    $rows = [
        ['customer', 'own', 'GET', '', 200, 200],
        ['customer', 'own', 'PATCH', '', 302, 200],
        ['customer', 'own', 'PATCH', '/status', 403, 403],
        ['customer', 'own', 'PATCH', '/priority', 403, 403],
        ['customer', 'other', 'GET', '', 404, 404],
        ['customer', 'other', 'PATCH', '', 404, 404],
        ['customer', 'other', 'PATCH', '/status', 404, 404],
        ['customer', 'other', 'PATCH', '/priority', 404, 404],
        ['agent', 'other', 'GET', '', 200, 200],
        ['agent', 'other', 'PATCH', '', 403, 403],
        ['agent', 'other', 'PATCH', '/status', 403, 403],
        ['agent', 'other', 'PATCH', '/priority', 403, 403],
        ['agent', 'own', 'PATCH', '', 302, 200],
        ['agent', 'own', 'PATCH', '/status', 302, 422],
        ['agent', 'own', 'PATCH', '/priority', 302, 422],
        ['admin', 'other', 'GET', '', 200, 200],
        ['admin', 'other', 'PATCH', '', 302, 200],
        ['admin', 'other', 'PATCH', '/status', 302, 422],
        ['admin', 'other', 'PATCH', '/priority', 302, 422],
    ];

    $cases = [];
    foreach ($rows as [$role, $ownership, $method, $suffix, $web, $api]) {
        $cases["web {$role} {$ownership} {$method} tickets/{n}{$suffix}"] = [$role, $ownership, $method, '/tickets/{n}'.$suffix, $web];
        $cases["api {$role} {$ownership} {$method} tickets/{n}{$suffix}"] = [$role, $ownership, $method, '/api/v1/tickets/{n}'.$suffix, $api];
    }

    $cases['api customer own activities'] = ['customer', 'own', 'GET', '/api/v1/tickets/{n}/activities', 200];
    $cases['api customer other activities'] = ['customer', 'other', 'GET', '/api/v1/tickets/{n}/activities', 404];
    $cases['api agent other activities'] = ['agent', 'other', 'GET', '/api/v1/tickets/{n}/activities', 200];

    return $cases;
});

it('enforces the ticket matrix', function (string $role, string $ownership, string $method, string $uri, int $status): void {
    $matrix = AuthorizationMatrix::for($this);
    $user = $matrix->actingAsRole(Role::from($role));

    $attributes = ['status' => TicketStatus::Open];
    if ($ownership === 'own' && $role === 'customer') {
        $attributes['customer_id'] = $user->id;
    }

    $factory = Ticket::factory()->status(TicketStatus::Open);
    $ticket = $ownership === 'own' && $role === 'agent'
        ? $factory->assignedTo($user)->create()
        : $factory->create($attributes);

    $matrix->expectAccess($method, str_replace('{n}', $ticket->number, $uri), $status);
})->with('ticket route matrix');

dataset('collection routes', [
    'web index' => ['GET', '/tickets', 200],
    'web create' => ['GET', '/tickets/create', 200],
    'web store' => ['POST', '/tickets', 302],
    'api index' => ['GET', '/api/v1/tickets', 200],
    'api store' => ['POST', '/api/v1/tickets', 422],
]);

it('lets every role reach the collection routes', function (string $method, string $uri, int $status, Role $role): void {
    $matrix = AuthorizationMatrix::for($this);
    $matrix->actingAsRole($role);

    $matrix->expectAccess($method, $uri, $status);
})->with('collection routes')->with([Role::Customer, Role::Agent, Role::Admin]);

it('requires authentication on every ticket route', function (string $method, string $uri, int $status): void {
    $ticket = Ticket::factory()->create();
    $uri = str_replace('{n}', $ticket->number, $uri);

    $response = str_starts_with($uri, '/api/') ? $this->json($method, $uri) : $this->call($method, $uri);

    $response->assertStatus($status);
})->with([
    ['GET', '/tickets', 302],
    ['GET', '/tickets/create', 302],
    ['POST', '/tickets', 302],
    ['GET', '/tickets/{n}', 302],
    ['PATCH', '/tickets/{n}', 302],
    ['PATCH', '/tickets/{n}/status', 302],
    ['PATCH', '/tickets/{n}/priority', 302],
    ['GET', '/api/v1/tickets', 401],
    ['POST', '/api/v1/tickets', 401],
    ['GET', '/api/v1/tickets/{n}', 401],
    ['PATCH', '/api/v1/tickets/{n}', 401],
    ['PATCH', '/api/v1/tickets/{n}/status', 401],
    ['PATCH', '/api/v1/tickets/{n}/priority', 401],
    ['GET', '/api/v1/tickets/{n}/activities', 401],
]);
