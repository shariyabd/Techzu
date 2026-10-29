<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Support\RouteAudit;

it('keeps the public and authentication-only lists in step with the real routes', function (): void {
    $keys = array_map(RouteAudit::key(...), RouteAudit::applicationRoutes());

    expect(array_values(array_diff([...RouteAudit::PUBLIC, ...RouteAudit::AUTHENTICATED_ONLY], $keys)))->toBe([]);
});

it('turns guests away from every route that is not public by design', function (): void {
    $offenders = [];

    foreach (RouteAudit::applicationRoutes() as $route) {
        if (in_array(RouteAudit::key($route), RouteAudit::PUBLIC, true)) {
            continue;
        }

        app('auth')->forgetGuards();
        $uri = RouteAudit::uriFor($route);

        if (RouteAudit::isApi($route)) {
            $status = $this->json(RouteAudit::method($route), $uri)->status();
            $refused = $status === 401;
        } else {
            $response = $this->call(RouteAudit::method($route), $uri);
            $status = $response->status();
            $refused = $status === 302 && $response->headers->get('Location') === route('login');
        }

        if (! $refused) {
            $offenders[] = RouteAudit::key($route)." → {$status}";
        }
    }

    expect($offenders)->toBe([]);
});

it('denies a user without any role on every route that needs a permission', function (): void {
    $user = User::factory()->create();
    $offenders = [];

    foreach (RouteAudit::applicationRoutes() as $route) {
        if (in_array(RouteAudit::key($route), [...RouteAudit::PUBLIC, ...RouteAudit::AUTHENTICATED_ONLY], true)) {
            continue;
        }

        app('auth')->forgetGuards();
        $uri = RouteAudit::uriFor($route);

        if (RouteAudit::isApi($route)) {
            Sanctum::actingAs($user, ['*']);
            $status = $this->json(RouteAudit::method($route), $uri)->status();
        } else {
            $status = $this->actingAs($user)->call(RouteAudit::method($route), $uri)->status();
        }

        if (! in_array($status, [403, 404], true)) {
            $offenders[] = RouteAudit::key($route)." → {$status}";
        }
    }

    expect($offenders)->toBe([]);
});
