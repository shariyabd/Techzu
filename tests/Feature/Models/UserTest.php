<?php

declare(strict_types=1);

use App\Models\User;

it('reports admin status from the spatie role', function (?string $state, bool $admin): void {
    $factory = User::factory();
    $user = ($state === null ? $factory : $factory->{$state}())->create();

    expect($user->isAdmin())->toBe($admin);
})->with([
    'admin' => ['admin', true],
    'agent' => ['agent', false],
    'customer' => ['customer', false],
    'no role' => [null, false],
]);

it('scopes available agents to the agent role with is_available', function (): void {
    $available = User::factory()->agent()->create();
    User::factory()->agent()->unavailable()->create();
    User::factory()->admin()->create();
    User::factory()->customer()->create();

    expect(User::query()->availableAgents()->pluck('id')->all())->toBe([$available->id]);
});

it('limits the admin list to users with the admin role', function (): void {
    $admin = User::factory()->admin()->create();
    User::factory()->agent()->create();
    User::factory()->customer()->create();

    expect(User::query()->admins()->pluck('id')->all())->toBe([$admin->id]);
});

it('never mass-assigns anything beyond profile fields and availability', function (): void {
    expect((new User)->getFillable())->toBe(['name', 'email', 'password', 'is_available']);
});
