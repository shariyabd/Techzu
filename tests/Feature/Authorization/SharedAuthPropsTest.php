<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Http\Request;
use Tests\Support\AuthorizationMatrix;

/** @return array<string, mixed> */
function sharedPropsFor(?User $user): array
{
    $request = Request::create('/');
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(static fn (): ?User => $user);

    return app(HandleInertiaRequests::class)->share($request);
}

it('shares only the capabilities each role holds so the UI hides forbidden controls', function (Role $role, array $expected): void {
    $user = AuthorizationMatrix::for($this)->actingAsRole($role);

    expect(sharedPropsFor($user)['auth']['can'])->toBe($expected);
})->with([
    'admin' => [Role::Admin, [
        'viewDashboard' => true, 'manageImports' => true, 'manageUsers' => true,
        'manageCategories' => true, 'manageHolidays' => true, 'createOnBehalf' => true,
    ]],
    'agent' => [Role::Agent, [
        'viewDashboard' => true, 'manageImports' => false, 'manageUsers' => false,
        'manageCategories' => false, 'manageHolidays' => false, 'createOnBehalf' => true,
    ]],
    'customer' => [Role::Customer, [
        'viewDashboard' => false, 'manageImports' => false, 'manageUsers' => false,
        'manageCategories' => false, 'manageHolidays' => false, 'createOnBehalf' => false,
    ]],
]);

it('shares no abilities and no user with guests', function (): void {
    AuthorizationMatrix::for($this)->asGuest();

    $shared = sharedPropsFor(null);

    expect($shared['auth']['user'])->toBeNull()
        ->and($shared['auth']['can'])->toBe([
            'viewDashboard' => false, 'manageImports' => false, 'manageUsers' => false,
            'manageCategories' => false, 'manageHolidays' => false, 'createOnBehalf' => false,
        ]);
});
