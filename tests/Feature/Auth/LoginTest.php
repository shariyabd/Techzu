<?php

declare(strict_types=1);

use App\Models\User;

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

it('logs the user out and redirects to the login page', function (): void {
    $this->actingAs(User::factory()->create())
        ->post(route('logout'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});
