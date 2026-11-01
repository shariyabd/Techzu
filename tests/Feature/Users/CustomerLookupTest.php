<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\AuthorizationMatrix;

dataset('customer lookup routes', [
    'web' => ['/customers/lookup'],
    'api' => ['/api/v1/customers/lookup'],
]);

/** @param array<string, mixed> $query */
function customerLookupAs(User $user, string $uri, array $query): TestResponse
{
    if (str_starts_with($uri, '/api/')) {
        Sanctum::actingAs($user, ['*']);
    } else {
        test()->actingAs($user);
    }

    return test()->getJson($uri.'?'.http_build_query($query));
}

it('lets only staff look up customers', function (string $uri, Role $role, int $status): void {
    $matrix = AuthorizationMatrix::for($this);
    $matrix->actingAsRole($role);

    $matrix->expectAccess('GET', $uri.'?q=a', $status);
})->with('customer lookup routes')->with([
    'admin' => [Role::Admin, 200],
    'agent' => [Role::Agent, 200],
    'customer' => [Role::Customer, 403],
]);

it('turns guests away from the lookup', function (): void {
    $this->get('/customers/lookup?q=a')->assertRedirect(route('login'));
    $this->getJson('/api/v1/customers/lookup?q=a')->assertUnauthorized();
});

it('returns only customers matching the name or email prefix, with minimal fields', function (string $uri): void {
    $agent = User::factory()->agent()->create(['name' => 'Alice Agent', 'email' => 'alice.agent@example.com']);
    $byName = User::factory()->customer()->create(['name' => 'Alice Customer', 'email' => 'first@example.com']);
    $byEmail = User::factory()->customer()->create(['name' => 'Bob', 'email' => 'alice@example.com']);
    User::factory()->customer()->create(['name' => 'Carol', 'email' => 'carol@example.com']);

    $response = customerLookupAs($agent, $uri, ['q' => 'ali'])->assertOk();

    expect($response->json('data'))->toBe([
        ['id' => $byName->id, 'name' => 'Alice Customer', 'email' => 'first@example.com'],
        ['id' => $byEmail->id, 'name' => 'Bob', 'email' => 'alice@example.com'],
    ]);
})->with('customer lookup routes');

it('treats LIKE wildcards in the term as literal characters', function (string $term): void {
    $agent = User::factory()->agent()->create();
    User::factory()->customer()->create(['name' => 'Anyone', 'email' => 'anyone@example.com']);
    $literal = User::factory()->customer()->create(['name' => $term.'literal', 'email' => 'literal@example.com']);

    expect(customerLookupAs($agent, '/customers/lookup', ['q' => $term])->assertOk()->json('data.*.id'))->toBe([$literal->id]);
})->with(['percent' => ['%'], 'underscore' => ['_'], 'backslash' => ['\\']]);

it('returns at most ten customers', function (): void {
    $agent = User::factory()->agent()->create();
    User::factory()->customer()->count(12)->sequence(fn ($sequence): array => ['name' => 'Same '.$sequence->index])->create();

    customerLookupAs($agent, '/customers/lookup', ['q' => 'Same'])->assertOk()->assertJsonCount(10, 'data');
});

it('resolves a selected customer by id but never a staff member', function (): void {
    $agent = User::factory()->agent()->create();
    $customer = User::factory()->customer()->create();

    expect(customerLookupAs($agent, '/customers/lookup', ['id' => $customer->id])->json('data.*.id'))->toBe([$customer->id])
        ->and(customerLookupAs($agent, '/customers/lookup', ['id' => $agent->id])->json('data'))->toBe([]);
});

it('throttles the lookup', function (): void {
    $agent = User::factory()->agent()->create();

    foreach (range(1, 60) as $attempt) {
        customerLookupAs($agent, '/api/v1/customers/lookup', ['q' => 'x'])->assertOk();
    }

    customerLookupAs($agent, '/api/v1/customers/lookup', ['q' => 'x'])->assertTooManyRequests();
});
