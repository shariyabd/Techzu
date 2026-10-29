<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role as RoleModel;

it('shares only whitelisted user fields, role names, placeholder abilities, flash and the unread count', function (): void {
    $user = User::factory()->create();
    $user->assignRole(RoleModel::findOrCreate('example-role'));

    $this->actingAs($user)
        ->withSession(['success' => 'Saved.'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('auth.user', fn (Assert $sharedUser) => $sharedUser
                ->where('id', $user->id)
                ->where('name', $user->name)
                ->where('email', $user->email)
                ->where('email_verified_at', $user->email_verified_at?->toIso8601String())
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
