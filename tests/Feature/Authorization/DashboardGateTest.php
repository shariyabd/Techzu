<?php

declare(strict_types=1);

use App\Enums\Role;
use Illuminate\Support\Facades\Gate;
use Tests\Support\AuthorizationMatrix;

it('lets staff view the dashboard and keeps customers out', function (Role $role, bool $expected): void {
    $user = AuthorizationMatrix::for($this)->actingAsRole($role);

    expect(Gate::forUser($user)->allows('viewDashboard'))->toBe($expected);
})->with([
    'admin' => [Role::Admin, true],
    'agent' => [Role::Agent, true],
    'customer' => [Role::Customer, false],
]);
