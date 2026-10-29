<?php

declare(strict_types=1);

use App\Http\Resources\V1\CategoryResource;
use App\Http\Resources\V1\UserSummaryResource;
use App\Models\Category;
use App\Models\User;

it('exposes only id and name for a user summary', function (): void {
    $user = User::factory()->create();

    expect((new UserSummaryResource($user))->resolve())->toBe(['id' => $user->id, 'name' => $user->name]);
});

it('exposes the public category fields', function (): void {
    $category = Category::factory()->create(['name' => 'Billing', 'slug' => 'billing']);

    expect((new CategoryResource($category))->resolve())
        ->toBe(['id' => $category->id, 'name' => 'Billing', 'slug' => 'billing', 'is_active' => true]);
});
