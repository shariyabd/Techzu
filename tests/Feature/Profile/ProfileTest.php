<?php

declare(strict_types=1);

use App\Actions\Profile\ChangePassword;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

it('renders the profile page with the read-only email and the current avatar for every role', function (Role $role): void {
    $user = User::factory()->{$role->value}()->withAvatar()->create();

    $this->withoutVite()->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('profile/Edit')
            ->where('profile.email', $user->email)
            ->where('profile.avatar_url', $user->avatarUrl())
            ->has('avatarRules'));
})->with([Role::Admin, Role::Agent, Role::Customer]);

it('updates only the signed-in user\'s name and never their email', function (): void {
    $user = User::factory()->customer()->create(['email' => 'me@example.com']);
    $other = User::factory()->customer()->create(['name' => 'Someone Else']);

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => 'New Name', 'email' => 'hijack@example.com', 'id' => $other->id])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($user->fresh()?->name)->toBe('New Name')
        ->and($user->fresh()?->email)->toBe('me@example.com')
        ->and($other->fresh()?->name)->toBe('Someone Else');
});

it('refuses a password change when the current password is wrong', function (string $channel): void {
    $user = User::factory()->agent()->create(['password' => 'old-password-123']);
    $payload = ['current_password' => 'not-my-password', 'password' => 'brand-new-password', 'password_confirmation' => 'brand-new-password'];

    if ($channel === 'api') {
        Sanctum::actingAs($user);
        $this->putJson(route('api.v1.me.password.update'), $payload)->assertUnprocessable()->assertJsonValidationErrors('current_password');
    } else {
        $this->actingAs($user)->put(route('profile.password.update'), $payload)->assertSessionHasErrors('current_password');
    }

    expect(Hash::check('old-password-123', (string) $user->fresh()?->password))->toBeTrue();
})->with(['web', 'api']);

it('keeps the current session and ends the user\'s other sessions when they change their password', function (): void {
    config()->set('session.driver', 'database');
    $user = User::factory()->customer()->create();
    $bystander = User::factory()->customer()->create();
    $user->createToken('cli');
    $current = str_repeat('c', 40);
    app('session')->driver()->setId($current);

    foreach ([[$current, $user], [str_repeat('o', 40), $user], [str_repeat('b', 40), $bystander]] as [$id, $owner]) {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $owner->id, 'payload' => '', 'last_activity' => time()]);
    }

    app(ChangePassword::class)->handle($user, 'brand-new-password');

    expect(DB::table('sessions')->orderBy('id')->pluck('id')->all())->toBe([str_repeat('b', 40), $current])
        ->and($user->tokens()->count())->toBe(0)
        ->and(Hash::check('brand-new-password', (string) $user->fresh()?->password))->toBeTrue();
});

it('keeps the API token used for the change and revokes the others', function (): void {
    $user = User::factory()->customer()->create(['password' => 'old-password-123']);
    $user->createToken('other');
    $current = $user->createToken('current');

    $this->withToken($current->plainTextToken)
        ->putJson(route('api.v1.me.password.update'), [
            'current_password' => 'old-password-123',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])
        ->assertNoContent();

    expect($user->tokens()->pluck('name')->all())->toBe(['current']);
});

it('updates the name over the API and returns the current user with its avatar url', function (): void {
    $user = User::factory()->agent()->create();
    Sanctum::actingAs($user);

    $this->patchJson(route('api.v1.me.profile.update'), ['name' => 'Api Name'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Api Name')
        ->assertJsonPath('data.avatar_url', null);
});
