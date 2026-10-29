<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/** @property array{stats: array<string, mixed>, my_tickets: Collection<int, Ticket>} $resource */
final class DashboardResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            ...$this->resource['stats'],
            'my_tickets' => TicketListResource::collection($this->resource['my_tickets']),
        ];
    }
}
