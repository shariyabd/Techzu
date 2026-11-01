<?php

declare(strict_types=1);

use App\Actions\Profile\UpdateAvatar;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AuthorizationMatrix;

it('lets staff see every avatar and customers only their own and staff avatars, hiding other customers with 404', function (?Role $viewerRole, string $subject, int $status): void {
    Storage::fake('local');
    $viewer = $viewerRole === null ? User::factory()->create() : AuthorizationMatrix::for($this)->actingAsRole($viewerRole);
    $owner = match ($subject) {
        'self' => $viewer,
        'agent' => User::factory()->agent()->create(),
        'admin' => User::factory()->admin()->create(),
        'customer' => User::factory()->customer()->create(),
    };
    app(UpdateAvatar::class)->handle($owner, UploadedFile::fake()->image('a.png', 64, 64));

    $this->actingAs($viewer)->get(route('avatars.show', $owner))->assertStatus($status);
})->with([
    'customer · own avatar → 200' => [Role::Customer, 'self', 200],
    'customer · agent avatar → 200' => [Role::Customer, 'agent', 200],
    'customer · admin avatar → 200' => [Role::Customer, 'admin', 200],
    'customer · another customer avatar → 404' => [Role::Customer, 'customer', 404],
    'agent · customer avatar → 200' => [Role::Agent, 'customer', 200],
    'agent · colleague avatar → 200' => [Role::Agent, 'agent', 200],
    'admin · customer avatar → 200' => [Role::Admin, 'customer', 200],
    'no role · agent avatar → 404' => [null, 'agent', 404],
    'no role · own avatar → 200' => [null, 'self', 200],
]);
