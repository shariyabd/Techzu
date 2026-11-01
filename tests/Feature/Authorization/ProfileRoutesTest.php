<?php

declare(strict_types=1);

use App\Enums\Role;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AuthorizationMatrix;

dataset('profile routes', function (): array {
    $everyone = fn (int $status, int $guest): array => ['admin' => $status, 'agent' => $status, 'customer' => $status, 'guest' => $guest];
    $routes = [
        ['GET', '/profile', $everyone(200, 302)],
        ['PATCH', '/profile', $everyone(302, 302)],
        ['PUT', '/profile/password', $everyone(302, 302)],
        ['POST', '/profile/avatar', $everyone(302, 302)],
        ['DELETE', '/profile/avatar', $everyone(302, 302)],
        ['PATCH', '/api/v1/me/profile', $everyone(200, 401)],
        ['PUT', '/api/v1/me/password', $everyone(204, 401)],
        ['POST', '/api/v1/me/avatar', $everyone(200, 401)],
        ['DELETE', '/api/v1/me/avatar', $everyone(200, 401)],
    ];
    $cases = [];
    foreach ($routes as [$method, $uri, $statuses]) {
        foreach ($statuses as $who => $status) {
            $cases["{$who} · {$method} {$uri} → {$status}"] = [$who, $method, $uri, $status];
        }
    }

    return $cases;
});

it('lets every signed-in role manage only their own profile', function (string $who, string $method, string $uri, int $status): void {
    Storage::fake('local');
    $this->withoutVite();
    $matrix = AuthorizationMatrix::for($this);
    $user = $who === 'guest' ? null : $matrix->actingAsRole(Role::from($who), ['password' => 'old-password-123']);
    if ($user === null) {
        $matrix->asGuest();
    }
    $payload = [
        'name' => 'Matrix Name',
        'current_password' => 'old-password-123',
        'password' => 'brand-new-password',
        'password_confirmation' => 'brand-new-password',
        'avatar' => UploadedFile::fake()->image('me.png', 128, 128),
    ];

    $response = $matrix->expectAccess($method, $uri, $status, $method === 'GET' ? [] : $payload);

    if ($who === 'guest' && $status === 302) {
        $response->assertRedirect(route('login'));
    }
})->with('profile routes');
