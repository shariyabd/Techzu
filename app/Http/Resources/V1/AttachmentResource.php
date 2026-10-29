<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Attachment */
final class AttachmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $base = $request->is('api/*') ? '/api/v1/attachments/' : '/attachments/';

        return [
            'id' => $this->id,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => (int) $this->size_bytes,
            'is_internal' => (bool) $this->is_internal,
            'download_url' => url($base.$this->id),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
