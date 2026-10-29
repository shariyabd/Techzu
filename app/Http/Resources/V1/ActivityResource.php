<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Activitylog\Models\Activity;

/** @mixin Activity */
final class ActivityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $causer = $this->relationLoaded('causer') ? $this->causer : null;

        return [
            'id' => $this->id,
            'type' => $this->event,
            'description' => $this->description,
            'properties' => $this->properties?->all() ?? [],
            'causer' => $causer instanceof User ? UserSummaryResource::make($causer)->resolve($request) : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
