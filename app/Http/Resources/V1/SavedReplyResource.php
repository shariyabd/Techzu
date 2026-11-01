<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\SavedReply;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SavedReply */
final class SavedReplyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'scope' => $this->isShared() ? 'team' : 'mine',
            'category' => $this->whenLoaded('category', fn (): ?array => $this->category === null
                ? null
                : ['id' => $this->category->id, 'name' => $this->category->name]),
            'category_id' => $this->category_id,
            'can' => [
                'update' => $user?->can('update', $this->resource) ?? false,
                'delete' => $user?->can('delete', $this->resource) ?? false,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
