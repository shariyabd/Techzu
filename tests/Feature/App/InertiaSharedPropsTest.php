<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role as RoleModel;

it('shares only whitelisted user fields, role names, placeholder abilities, flash and the unread count', function (): void {
    Route::middleware(['web', 'auth'])->get('/_shared-props-probe', fn (): Response => Inertia::render('SharedPropsProbe'));
    $user = User::factory()->create();
    $user->assignRole(RoleModel::findOrCreate('example-role'));
    $this->withoutVite()->actingAs($user)
        ->withSession(['success' => 'Saved.'])
        ->get('/_shared-props-probe')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('SharedPropsProbe', false)
            ->has('auth.user', fn (Assert $sharedUser) => $sharedUser
                ->where('id', $user->id)
                ->where('name', $user->name)
                ->where('email', $user->email)
                ->where('roles', ['example-role']))
            ->where('auth.can', [
                'viewDashboard' => false,
                'manageImports' => false,
                'manageUsers' => false,
                'manageCategories' => false,
                'manageHolidays' => false,
                'createOnBehalf' => false,
            ])
            ->has('flash', fn (Assert $flash) => $flash
                ->where('success', 'Saved.')
                ->where('error', null))
            ->where('notifications.unread_count', 0));
});
