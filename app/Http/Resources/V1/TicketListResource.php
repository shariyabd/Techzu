<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Sla\SlaClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Ticket */
final class TicketListResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $now = CarbonImmutable::now();
        $state = $this->resource->slaState($now);

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
                'clock' => app(SlaClock::class)->for($this->resource, $now),
            ],
            'escalation_level' => $this->when(TicketResource::seesEscalation($request), fn (): int => $this->escalation_level->value),
            'replies_count' => $this->whenCounted('replies'),
            'has_agent_response' => $this->whenHas('has_agent_response', fn (): bool => (bool) $this->resource->getAttribute('has_agent_response')),
            'latest_message' => $this->whenHas('latest_reply_preview', fn (): ?string => $this->latestMessagePreview()),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }

    private function latestMessagePreview(): ?string
    {
        $preview = $this->resource->getAttribute('latest_reply_preview') ?? $this->resource->getAttribute('description_preview');

        if (! is_string($preview)) {
            return null;
        }

        $line = trim((string) preg_replace('/\s+/u', ' ', $preview));

        return $line === '' ? null : $line;
    }
}
