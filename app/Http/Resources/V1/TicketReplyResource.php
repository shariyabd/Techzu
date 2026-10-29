<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\TicketReply;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TicketReply */
final class TicketReplyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     *
     * @throws AuthorizationException
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        if ($this->is_internal && (! $viewer instanceof User || $viewer->cannot('view', $this->resource))) {
            throw new AuthorizationException('This reply is not visible to you.');
        }

        return [
            'id' => $this->id,
            'body' => $this->body,
            'is_internal' => $this->is_internal,
            'author' => $this->whenLoaded('author', fn (User $author): array => UserSummaryResource::make($author)->resolve($request)),
            'attachments' => $this->whenLoaded('attachments', fn (Collection $attachments): array => AttachmentResource::collection($attachments)->resolve($request)),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
