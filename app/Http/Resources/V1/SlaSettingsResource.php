<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Services\Sla\SlaSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property SlaSettings $resource */
final class SlaSettingsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            ...$this->resource->toInput(),
            'customized' => $this->resource->stored,
        ];
    }
}
