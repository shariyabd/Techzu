<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia;

const RESET_LINK_SENT = 'If an account exists for that email, a password reset link has been sent.';

beforeEach(function (): void {
    Notification::fake();
});

it('renders the forgot and reset password pages for guests', function (): void {
    $this->get(route('password.request'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/ForgotPassword'));

    $this->get(route('password.reset', ['token' => 'abc', 'email' => 'jane@example.com']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/ResetPassword')
            ->where('token', 'abc')
            ->where('email', 'jane@example.com'));
});

it('emails a reset link that points at the reset page', function (): void {
    $user = User::factory()->create();

    $this->from(route('password.request'))
        ->post(route('password.email'), ['email' => $user->email])
        ->assertRedirect(route('password.request'))
        ->assertSessionHas('success', RESET_LINK_SENT);

    Notification::assertSentTo($user, ResetPassword::class, fn (ResetPassword $notification): bool => $notification->toMail($user)->actionUrl
        === route('password.reset', ['token' => $notification->token, 'email' => $user->email]));
});

it('answers an unknown email exactly like a known one and sends nothing', function (): void {
    $this->from(route('password.request'))
        ->post(route('password.email'), ['email' => 'nobody@example.com'])
        ->assertRedirect(route('password.request'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', RESET_LINK_SENT);

    Notification::assertNothingSent();
});

it('resets the password with a valid token and revokes every api token', function (): void {
    $user = User::factory()->create();
    $user->createToken('laptop');
    $token = Password::createToken($user);

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertRedirect(route('login'))
        ->assertSessionHas('success');

    $user->refresh();

    expect(Hash::check('new-password', $user->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0);
    $this->assertGuest();
});

it('refuses an invalid token without revealing whether the email exists', function (string $email): void {
    $user = User::factory()->create(['email' => 'jane@example.com']);

    $this->from('/reset-password/bogus')
        ->post(route('password.update'), [
            'token' => 'bogus',
            'email' => $email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertRedirect('/reset-password/bogus')
        ->assertSessionHasErrors(['email' => __('passwords.token')]);

    expect(Hash::check('password', (string) $user->fresh()?->password))->toBeTrue();
})->with([
    'known email' => ['jane@example.com'],
    'unknown email' => ['nobody@example.com'],
]);

it('throttles the password reset forms to five attempts per minute', function (string $route, array $payload): void {
    foreach (range(1, 5) as $attempt) {
        $this->post(route($route), $payload)->assertRedirect();
    }

    $this->post(route($route), $payload)->assertTooManyRequests();
})->with([
    'link request' => ['password.email', ['email' => 'nobody@example.com']],
    'reset' => ['password.update', ['token' => 'bogus', 'email' => 'nobody@example.com', 'password' => 'new-password', 'password_confirmation' => 'new-password']],
]);
