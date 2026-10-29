<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Category;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
final class AdminUserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->relationLoaded('roles') ? $this->getRoleNames()->first() : null,
            'is_available' => (bool) $this->is_available,
            'last_assigned_at' => $this->last_assigned_at?->toIso8601String(),
            'categories' => $this->whenLoaded('categories', fn (): array => $this->categories
                ->map(static fn (Category $category): array => ['id' => $category->id, 'name' => $category->name])
                ->values()
                ->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
