<?php

declare(strict_types=1);

use App\Actions\Profile\UpdateAvatar;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    Storage::fake('local');
});

it('rejects a profile image that is not an allowed image or breaks the size limits', function (Closure $file): void {
    $user = User::factory()->customer()->create();

    $this->actingAs($user)
        ->post(route('profile.avatar.update'), ['avatar' => $file()])
        ->assertSessionHasErrors('avatar');

    expect($user->fresh()?->avatar_path)->toBeNull()
        ->and(Storage::disk('local')->allFiles('avatars'))->toBe([]);
})->with([
    'pdf' => [fn (): UploadedFile => UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf')],
    'gif' => [fn (): UploadedFile => UploadedFile::fake()->image('anim.gif', 100, 100)],
    'svg' => [fn (): UploadedFile => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"/>')],
    'over 2 MB' => [fn (): UploadedFile => UploadedFile::fake()->image('big.png', 100, 100)->size(2049)],
    'under 64 px' => [fn (): UploadedFile => UploadedFile::fake()->image('tiny.png', 63, 63)],
    'over 4096 px' => [fn (): UploadedFile => UploadedFile::fake()->image('huge.png', 4097, 64)],
]);

it('stores the image on the private disk as a 256 px square and deletes the old file on replace and remove', function (): void {
    $user = User::factory()->agent()->create();
    $action = app(UpdateAvatar::class);

    $action->handle($user, UploadedFile::fake()->image('first.jpg', 400, 200));
    $first = (string) $user->avatar_path;

    $size = getimagesizefromstring((string) Storage::disk('local')->get($first));
    expect($first)->toStartWith('avatars/')
        ->and($size[0] ?? null)->toBe(256)
        ->and($size[1] ?? null)->toBe(256);

    $action->handle($user, UploadedFile::fake()->image('second.png', 300, 300));
    $second = (string) $user->avatar_path;

    expect($second)->not->toBe($first);
    Storage::disk('local')->assertMissing($first)->assertExists($second);

    $action->handle($user, null);

    expect($user->fresh()?->avatar_path)->toBeNull();
    Storage::disk('local')->assertMissing($second);
});

it('uploads and removes the signed-in user\'s avatar over the API', function (): void {
    $user = User::factory()->customer()->create();
    Sanctum::actingAs($user);

    $url = $this->postJson(route('api.v1.me.avatar.update'), ['avatar' => UploadedFile::fake()->image('me.png', 128, 128)])
        ->assertOk()
        ->json('data.avatar_url');

    expect($url)->toBe($user->fresh()?->avatarUrl())->toContain('?v=');

    $this->deleteJson(route('api.v1.me.avatar.destroy'))
        ->assertOk()
        ->assertJsonPath('data.avatar_url', null);
});

it('serves an avatar to any signed-in user with its stored type, nosniff and a cache validator', function (): void {
    $agent = User::factory()->agent()->create();
    app(UpdateAvatar::class)->handle($agent, UploadedFile::fake()->image('a.png', 100, 100));
    $viewer = User::factory()->customer()->create();

    $response = $this->actingAs($viewer)->get((string) $agent->avatarUrl())
        ->assertOk()
        ->assertHeader('Content-Type', 'image/webp')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->headers->get('Cache-Control'))->toContain('private')
        ->and($response->headers->get('ETag'))->not->toBeNull();

    $this->actingAs($viewer)
        ->get((string) $agent->avatarUrl(), ['If-None-Match' => (string) $response->headers->get('ETag')])
        ->assertStatus(304);
});

it('keeps avatars away from guests and answers 404 for a user without one', function (): void {
    $agent = User::factory()->agent()->create();
    app(UpdateAvatar::class)->handle($agent, UploadedFile::fake()->image('a.png', 100, 100));
    $plain = User::factory()->customer()->create();

    $this->get((string) $agent->avatarUrl())->assertRedirect(route('login'));
    $this->getJson((string) $agent->avatarUrl())->assertUnauthorized();
    $this->actingAs($plain)->get(route('avatars.show', $plain))->assertNotFound();
});

it('lets an admin set and remove an agent\'s avatar from the user edit form, and refuses an agent', function (Role $role, int $status): void {
    $editor = User::factory()->{$role->value}()->create();
    $agent = User::factory()->agent()->create();
    $payload = [
        '_method' => 'PATCH',
        'name' => 'Renamed Agent',
        'email' => $agent->email,
        'role' => Role::Agent->value,
        'avatar' => UploadedFile::fake()->image('agent.png', 128, 128),
    ];

    $this->actingAs($editor)->post(route('admin.users.update', $agent), $payload)->assertStatus($status);

    if ($status === 403) {
        expect($agent->fresh()?->avatar_path)->toBeNull()
            ->and($agent->fresh()?->name)->not->toBe('Renamed Agent');

        return;
    }

    $stored = (string) $agent->fresh()?->avatar_path;
    expect($agent->fresh()?->name)->toBe('Renamed Agent');
    Storage::disk('local')->assertExists($stored);

    unset($payload['avatar']);
    $this->actingAs($editor)->post(route('admin.users.update', $agent), [...$payload, 'remove_avatar' => true])->assertRedirect();

    expect($agent->fresh()?->avatar_path)->toBeNull();
    Storage::disk('local')->assertMissing($stored);
})->with([
    'admin' => [Role::Admin, 302],
    'agent' => [Role::Agent, 403],
]);
