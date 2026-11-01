<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Category;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
final class AgentDirectoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar_url' => $this->resource->avatarUrl(),
            'role' => $this->relationLoaded('roles') ? $this->getRoleNames()->first() : null,
            'is_available' => (bool) $this->is_available,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'categories' => $this->whenLoaded('categories', fn (): array => $this->categories
                ->map(static fn (Category $category): array => ['id' => $category->id, 'name' => $category->name])
                ->values()
                ->all()),
            'stats' => [
                'total_assigned' => $this->stat('total_assigned_count'),
                'active' => $this->stat('active_count'),
                'unresponded' => $this->stat('unresponded_count'),
                'awaiting_reply' => $this->stat('awaiting_reply_count'),
                'breached' => $this->stat('breached_count'),
                'resolved_recent' => $this->stat('resolved_recent_count'),
            ],
        ];
    }

    private function stat(string $column): int
    {
        return (int) $this->resource->getAttribute($column);
    }
}
