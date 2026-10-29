<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Category;
use App\Models\Holiday;
use App\Models\TicketImport;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\Support\AuthorizationMatrix;

it('limits admin areas to admins', function (string $model, string $ability, Role $role, int $expected): void {
    $user = AuthorizationMatrix::for($this)->actingAsRole($role);
    $argument = in_array($ability, ['viewAny', 'create'], true) ? $model : new $model;

    expect(AuthorizationMatrix::statusOf(Gate::forUser($user)->inspect($ability, $argument)))->toBe($expected);
})->with([
    'categories' => [Category::class],
    'holidays' => [Holiday::class],
    'users' => [User::class],
    'imports' => [TicketImport::class],
])->with(['viewAny', 'view', 'create', 'update', 'delete'])
    ->with([
        'admin → 200' => [Role::Admin, 200],
        'agent → 403' => [Role::Agent, 403],
        'customer → 403' => [Role::Customer, 403],
    ]);

it('stops an admin from deleting their own account', function (): void {
    $admin = AuthorizationMatrix::for($this)->actingAsRole(Role::Admin);
    $other = User::factory()->agent()->create();

    expect($admin->can('delete', $admin))->toBeFalse()
        ->and($admin->can('delete', $other))->toBeTrue()
        ->and($admin->can('update', $admin))->toBeTrue();
});
