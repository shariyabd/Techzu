<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;

it('issues a token whose abilities mirror the user permission names', function (): void {
    $user = User::factory()->create(['email' => 'agent@example.com']);
    PermissionModel::findOrCreate('example.read');
    PermissionModel::findOrCreate('example.write');
    $role = RoleModel::findOrCreate('example-role');
    $role->givePermissionTo('example.read');
    $user->assignRole($role);
    $user->givePermissionTo('example.write');

    $response = $this->postJson(route('api.v1.auth.tokens.store'), [
        'email' => 'agent@example.com',
        'password' => 'password',
        'device_name' => 'cli',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonStructure(['data' => ['token', 'token_type', 'abilities']]);

    expect($response->json('data.abilities'))->toEqualCanonicalizing(['example.read', 'example.write']);

    $token = PersonalAccessToken::findToken($response->json('data.token'));

    expect($token)->not->toBeNull()
        ->and($token->name)->toBe('cli')
        ->and($token->tokenable_id)->toBe($user->id)
        ->and($token->abilities)->toEqualCanonicalizing(['example.read', 'example.write']);
});

it('refuses a token without creating one', function (Closure $payload): void {
    $this->postJson(route('api.v1.auth.tokens.store'), [...$payload(), 'device_name' => 'cli'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);

    expect(PersonalAccessToken::query()->count())->toBe(0);
})->with([
    'unknown email' => [fn (): array => ['email' => 'nobody@example.com', 'password' => 'password']],
    'wrong password' => [fn (): array => ['email' => User::factory()->create()->email, 'password' => 'not-the-password']],
    'unverified email' => [fn (): array => ['email' => User::factory()->unverified()->create()->email, 'password' => 'password']],
]);

it('throttles token issuance to five attempts per minute', function (): void {
    $user = User::factory()->create();
    $payload = ['email' => $user->email, 'password' => 'wrong', 'device_name' => 'cli'];

    foreach (range(1, 5) as $attempt) {
        $this->postJson(route('api.v1.auth.tokens.store'), $payload)->assertUnprocessable();
    }

    $this->postJson(route('api.v1.auth.tokens.store'), $payload)->assertStatus(429);
});

it('revokes only the current token', function (): void {
    $user = User::factory()->create();
    $current = $user->createToken('laptop');
    $user->createToken('phone');

    $this->withToken($current->plainTextToken)
        ->deleteJson(route('api.v1.auth.tokens.destroy'))
        ->assertNoContent();

    expect($user->tokens()->pluck('name')->all())->toBe(['phone']);

    app('auth')->forgetGuards();

    $this->withToken($current->plainTextToken)
        ->getJson(route('api.v1.auth.me'))
        ->assertUnauthorized();
});

it('returns the authenticated user with roles and permissions', function (): void {
    $user = User::factory()->create();
    PermissionModel::findOrCreate('example.read');
    $user->givePermissionTo('example.read');

    Sanctum::actingAs($user);

    $this->getJson(route('api.v1.auth.me'))
        ->assertOk()
        ->assertExactJson(['data' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => [],
            'permissions' => ['example.read'],
        ]]);
});

it('rejects unauthenticated access to me and revoke', function (string $method, string $name): void {
    $this->json($method, route($name))->assertUnauthorized();
})->with([
    'me' => ['GET', 'api.v1.auth.me'],
    'revoke' => ['DELETE', 'api.v1.auth.tokens.destroy'],
]);
