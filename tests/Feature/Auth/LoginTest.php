<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia;

it('logs a user in with valid credentials and redirects home', function (): void {
    $user = User::factory()->create();

    $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($user);
});

it('rejects bad credentials with an email error and keeps the visitor a guest', function (): void {
    $user = User::factory()->create();

    $this->from(route('login'))
        ->post(route('login'), ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => __('auth.failed')]);

    $this->assertGuest();
});

it('throttles login after five attempts per minute', function (): void {
    $payload = ['email' => 'nobody@example.com', 'password' => 'wrong-password'];

    foreach (range(1, 5) as $attempt) {
        $this->post(route('login'), $payload)->assertSessionHasErrors('email');
    }

    $this->post(route('login'), $payload)->assertTooManyRequests();
});

it('sends a signed-in user who opens the login page to home', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('login'))
        ->assertRedirect(route('home'));
});

it('logs the user out and wipes the browser history so Back cannot replay private pages', function (): void {
    $this->actingAs(User::factory()->create())
        ->post(route('logout'))
        ->assertRedirect(route('login'));

    $this->assertGuest();

    $this->get(route('login'))->assertInertia(fn (AssertableInertia $page) => expect($page->toArray())
        ->toMatchArray(['clearHistory' => true, 'encryptHistory' => true]));
});

it('shows the demo accounts on the login page only while they are enabled', function (bool $enabled, array $roles): void {
    config(['demo.login_accounts.enabled' => $enabled]);

    $this->get(route('login'))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('auth/Login')
        ->where('demoAccounts', fn ($accounts): bool => collect($accounts)->pluck('role')->all() === $roles));
})->with([
    'enabled' => [true, ['admin', 'agent', 'customer']],
    'disabled' => [false, []],
]);
