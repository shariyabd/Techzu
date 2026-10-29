<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\AuthorizationMatrix;

it('serves the API docs UI and JSON to admins outside local', function (): void {
    expect(app()->environment('local'))->toBeFalse();
    $this->actingAs(User::factory()->admin()->create());

    $this->get('/docs/api')->assertOk();
    $this->get('/docs/api.json')
        ->assertOk()
        ->assertJsonStructure(['openapi', 'info' => ['title', 'version'], 'paths']);
});

it('hides the API docs from agents, customers and guests outside local', function (?Role $role, string $uri): void {
    $matrix = AuthorizationMatrix::for($this);
    $role === null ? $matrix->asGuest() : $matrix->actingAsRole($role);

    $matrix->expectAccess('GET', $uri, 403);
})->with([
    'guest' => [null],
    'agent' => [Role::Agent],
    'customer' => [Role::Customer],
])->with(['/docs/api', '/docs/api.json']);

it('exports an OpenAPI document that lists every api/v1 route', function (): void {
    $spec = json_decode((string) file_get_contents(base_path('docs/api/openapi.json')), true, 512, JSON_THROW_ON_ERROR);
    $documented = array_keys($spec['paths']);

    $missing = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 'api/v1/'))
        ->map(fn (RoutingRoute $route): string => '/'.Str::after($route->uri(), 'api/v1/'))
        ->unique()
        ->reject(fn (string $path): bool => in_array($path, $documented, true))
        ->values()
        ->all();

    expect($spec['openapi'])->toStartWith('3.')
        ->and($missing)->toBe([]);
});
