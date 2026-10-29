<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\TicketImport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TicketImport */
final class TicketImportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $finished = $this->status->isFinished();

        return [
            'id' => $this->id,
            'original_name' => $this->original_name,
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'is_finished' => $finished,
            'total_rows' => $this->total_rows,
            'processed_rows' => $this->processed_rows,
            'succeeded_rows' => $this->succeeded_rows,
            'failed_rows' => $this->failed_rows,
            'progress_percent' => $this->total_rows === 0
                ? ($finished ? 100 : 0)
                : min(100, intdiv($this->processed_rows * 100, $this->total_rows)),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'user' => UserSummaryResource::make($this->whenLoaded('user')),
        ];
    }
}
