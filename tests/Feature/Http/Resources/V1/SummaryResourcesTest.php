<?php

declare(strict_types=1);

use App\Http\Resources\V1\CategoryResource;
use App\Http\Resources\V1\UserSummaryResource;
use App\Models\Category;
use App\Models\User;

it('exposes only id, name, avatar url and last seen time for a user summary', function (): void {
    $user = User::factory()->withAvatar()->create(['last_seen_at' => '2026-03-02 10:00:00']);

    expect((new UserSummaryResource($user))->resolve())->toBe([
        'id' => $user->id,
        'name' => $user->name,
        'avatar_url' => $user->avatarUrl(),
        'last_seen_at' => '2026-03-02T10:00:00+00:00',
    ])->and($user->avatarUrl())->toContain('?v=');
});

it('exposes the public category fields', function (): void {
    $category = Category::factory()->create(['name' => 'Billing', 'slug' => 'billing']);

    expect((new CategoryResource($category))->resolve())
        ->toBe(['id' => $category->id, 'name' => 'Billing', 'slug' => 'billing', 'is_active' => true]);
});
