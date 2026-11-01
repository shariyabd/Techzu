<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
final class UserSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'avatar_url' => $this->resource instanceof User ? $this->resource->avatarUrl() : null,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
        ];
    }
}
