<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('registers a new user, logs them in and redirects home', function (): void {
    $this->post(route('register'), [
        'name' => 'Jane Customer',
        'email' => 'jane@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('home'));

    $user = User::query()->where('email', 'jane@example.com')->sole();

    $this->assertAuthenticatedAs($user);
    expect(Hash::check('password', $user->password))->toBeTrue();
});

it('rejects an email that is already taken', function (): void {
    User::factory()->create(['email' => 'jane@example.com']);

    $this->from(route('register'))
        ->post(route('register'), [
            'name' => 'Jane Again',
            'email' => 'jane@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
        ->assertRedirect(route('register'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(User::query()->count())->toBe(1);
});
