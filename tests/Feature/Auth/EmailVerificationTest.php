<?php

declare(strict_types=1);

use App\Actions\Admin\CreateUser;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;

function verificationUrl(User $user, ?string $hash = null): string
{
    return URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
        'id' => $user->id,
        'hash' => $hash ?? sha1($user->email),
    ]);
}

it('sends a verification email on registration and leaves the new customer unverified on the notice', function (): void {
    Notification::fake();

    $this->post(route('register'), [
        'name' => 'Jane Customer',
        'email' => 'jane@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('verification.notice'))
        ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'jane@example.com'));

    $user = User::query()->where('email', 'jane@example.com')->sole();

    expect($user->hasVerifiedEmail())->toBeFalse();
    $this->assertAuthenticatedAs($user);
    Notification::assertSentToTimes($user, VerifyEmail::class, 1);
});

it('sends an unverified user from any app page to the verification notice', function (string $uri): void {
    $user = User::factory()->unverified()->admin()->create();

    $this->actingAs($user)->get($uri)->assertRedirect(route('verification.notice'));
})->with([
    'tickets' => ['/tickets'],
    'dashboard' => ['/dashboard'],
    'profile' => ['/profile'],
    'notifications' => ['/notifications'],
    'admin users' => ['/admin/users'],
    'saved replies' => ['/saved-replies'],
]);

it('refuses the broadcasting handshake to an unverified user', function (): void {
    $user = User::factory()->unverified()->customer()->create();

    $this->actingAs($user)
        ->post('/broadcasting/auth', ['channel_name' => 'private-App.Models.User.'.$user->id, 'socket_id' => '1.1'])
        ->assertRedirect(route('verification.notice'));
});

it('shows the notice with the local mail hint only when mail goes to the log locally', function (string $environment, string $mailer, bool $hint): void {
    $user = User::factory()->unverified()->customer()->create();
    app()->detectEnvironment(fn (): string => $environment);
    config(['mail.default' => $mailer]);

    $this->withoutVite()
        ->actingAs($user)
        ->get(route('verification.notice'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('auth/VerifyEmail')
            ->where('email', $user->email)
            ->where('mailIsLogged', $hint));
})->with([
    'local with log mailer' => ['local', 'log', true],
    'local with smtp mailer' => ['local', 'smtp', false],
    'production with log mailer' => ['production', 'log', false],
]);

it('verifies the email from a valid signed link and sends the user home', function (): void {
    $user = User::factory()->unverified()->customer()->create();

    $this->actingAs($user)
        ->get(verificationUrl($user))
        ->assertRedirect(route('home'))
        ->assertSessionHas('success');

    expect($user->fresh()?->hasVerifiedEmail())->toBeTrue();
});

it('keeps the verified message through the home redirect so the landing page shows it', function (): void {
    $user = User::factory()->unverified()->customer()->create();

    $this->withoutVite()
        ->actingAs($user)
        ->followingRedirects()
        ->get(verificationUrl($user))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('tickets/Index')
            ->where('flash.success', 'Your email address is verified. Welcome aboard!'));
});

it('rejects a verification link whose hash does not match the email', function (): void {
    $user = User::factory()->unverified()->customer()->create();

    $this->actingAs($user)->get(verificationUrl($user, sha1('someone@else.test')))->assertForbidden();

    expect($user->fresh()?->hasVerifiedEmail())->toBeFalse();
});

it('rejects a verification link whose signature was tampered with', function (): void {
    $user = User::factory()->unverified()->customer()->create();

    $this->actingAs($user)->get(verificationUrl($user).'x')->assertForbidden();

    expect($user->fresh()?->hasVerifiedEmail())->toBeFalse();
});

it('resends the verification email on request', function (): void {
    Notification::fake();
    $user = User::factory()->unverified()->customer()->create();

    $this->actingAs($user)
        ->from(route('verification.notice'))
        ->post(route('verification.send'))
        ->assertRedirect(route('verification.notice'))
        ->assertSessionHas('success', fn (string $message): bool => str_contains($message, $user->email));

    Notification::assertSentToTimes($user, VerifyEmail::class, 1);
});

it('sends an already verified user home instead of resending or showing the notice', function (): void {
    Notification::fake();
    $user = User::factory()->customer()->create();

    $this->actingAs($user)->get(route('verification.notice'))->assertRedirect(route('home'));
    $this->actingAs($user)->post(route('verification.send'))->assertRedirect(route('home'));

    Notification::assertNothingSent();
});

it('throttles resending the verification email', function (): void {
    Notification::fake();
    $user = User::factory()->unverified()->customer()->create();

    foreach (range(1, 6) as $attempt) {
        $this->actingAs($user)->post(route('verification.send'))->assertRedirect();
    }

    $this->actingAs($user)->post(route('verification.send'))->assertTooManyRequests();
});

it('lets an unverified user log in and lands them on the notice', function (): void {
    $user = User::factory()->unverified()->customer()->create();

    $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('home'));
    $this->get(route('home'))->assertRedirect(route('tickets.index'));
    $this->get(route('tickets.index'))->assertRedirect(route('verification.notice'));
});

it('creates admin-made users already verified', function (Role $role): void {
    $admin = User::factory()->admin()->create();

    $user = app(CreateUser::class)->handle($admin, [
        'name' => 'Staff Member',
        'email' => 'staff@example.com',
        'password' => 'password',
        'role' => $role->value,
        'category_ids' => [],
        'is_available' => true,
    ]);

    expect($user->fresh()?->hasVerifiedEmail())->toBeTrue();
})->with([Role::Agent, Role::Customer, Role::Admin]);

it('refuses an API token to an unverified user', function (): void {
    $user = User::factory()->unverified()->customer()->create();

    $this->postJson(route('api.v1.auth.tokens.store'), [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'cli',
    ])->assertForbidden()->assertJsonPath('code', 'email_unverified');

    expect($user->tokens()->count())->toBe(0);
});
