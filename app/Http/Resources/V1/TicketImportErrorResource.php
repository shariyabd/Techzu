<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\TicketImportError;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TicketImportError */
final class TicketImportErrorResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'row_number' => $this->row_number,
            'errors' => $this->errors,
            'raw' => $this->raw,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
