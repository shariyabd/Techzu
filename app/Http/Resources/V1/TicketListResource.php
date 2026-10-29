<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Ticket */
final class TicketListResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $state = $this->resource->slaState();

        return [
            'number' => $this->number,
            'subject' => $this->subject,
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'priority' => ['value' => $this->priority->value, 'label' => $this->priority->label()],
            'category' => $this->whenLoaded('category', fn (Category $category) => CategoryResource::make($category)->resolve($request)),
            'customer' => $this->whenLoaded('customer', fn (User $customer) => UserSummaryResource::make($customer)->resolve($request)),
            'assigned_agent' => $this->whenLoaded('assignedAgent', fn (User $agent) => UserSummaryResource::make($agent)->resolve($request)),
            'sla' => [
                'state' => ['value' => $state->value, 'label' => $state->label()],
                'due_at' => $this->sla_due_at->toIso8601String(),
            ],
            'escalation_level' => $this->escalation_level->value,
            'replies_count' => $this->whenCounted('replies'),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
