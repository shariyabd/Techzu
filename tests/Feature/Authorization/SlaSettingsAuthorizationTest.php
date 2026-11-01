<?php

declare(strict_types=1);

use App\Enums\Role;
use Tests\Support\AuthorizationMatrix;

dataset('sla settings routes', function (): array {
    $admin = fn (int $status, int $guest): array => ['admin' => $status, 'agent' => 403, 'customer' => 403, 'guest' => $guest];
    $routes = [
        ['GET', '/admin/sla', $admin(200, 302)],
        ['PUT', '/admin/sla', $admin(302, 302)],
        ['GET', '/api/v1/admin/sla', $admin(200, 401)],
        ['PUT', '/api/v1/admin/sla', $admin(422, 401)],
    ];
    $cases = [];
    foreach ($routes as [$method, $uri, $statuses]) {
        foreach ($statuses as $who => $status) {
            $cases["{$who} · {$method} {$uri} → {$status}"] = [$who, $method, $uri, $status];
        }
    }

    return $cases;
});

it('lets only admins view and change the SLA settings', function (string $who, string $method, string $uri, int $status): void {
    $this->withoutVite();
    $matrix = AuthorizationMatrix::for($this);
    if ($who === 'guest') {
        $matrix->asGuest();
    } else {
        $matrix->actingAsRole(Role::from($who));
    }
    $response = $matrix->expectAccess($method, $uri, $status);
    if ($who === 'guest' && $status === 302) {
        $response->assertRedirect(route('login'));
    }
})->with('sla settings routes');
