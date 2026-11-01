<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Enums\Permission;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Sla\SlaClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Ticket */
final class TicketResource extends JsonResource
{
    private bool $includeAttachments = false;

    public function withAttachments(): self
    {
        $this->includeAttachments = true;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $now = CarbonImmutable::now();
        $state = $this->resource->slaState($now);
        $user = $request->user();

        return [
            'number' => $this->number,
            'subject' => $this->subject,
            'description' => $this->description,
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'priority' => ['value' => $this->priority->value, 'label' => $this->priority->label()],
            'category' => $this->whenLoaded('category', fn (Category $category) => CategoryResource::make($category)->resolve($request)),
            'customer' => $this->whenLoaded('customer', fn (User $customer) => UserSummaryResource::make($customer)->resolve($request)),
            'created_by' => $this->whenLoaded('creator', fn (User $creator) => UserSummaryResource::make($creator)->resolve($request)),
            'assigned_agent' => $this->whenLoaded('assignedAgent', fn (User $agent) => UserSummaryResource::make($agent)->resolve($request)),
            'sla' => [
                'state' => ['value' => $state->value, 'label' => $state->label()],
                'due_at' => $this->sla_due_at->toIso8601String(),
                'warning_at' => $this->sla_warning_at->toIso8601String(),
                'paused_at' => $this->sla_paused_at?->toIso8601String(),
                'clock' => app(SlaClock::class)->for($this->resource, $now),
            ],
            'escalation_level' => $this->when(self::seesEscalation($request), fn (): int => $this->escalation_level->value),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'attachments' => $this->when($this->includeAttachments, fn () => AttachmentResource::collection($this->resource->attachments)->resolve($request)),
            'can' => $this->when($user instanceof User, fn (): array => self::abilities($user, $this->resource)),
        ];
    }

    /** @return array{update: bool, editDescription: bool, changeStatus: bool, changePriority: bool, claim: bool, assign: bool, assignOutsideCategory: bool, reply: bool, addInternalNote: bool, allowedStatuses: list<string>} */
    public static function abilities(User $user, Ticket $ticket): array
    {
        $allowed = array_values(array_filter(
            $ticket->status->allowedTransitions(),
            static fn (TicketStatus $to): bool => $user->can('changeStatus', [$ticket, $to]),
        ));

        return [
            'update' => $user->can('update', $ticket),
            'editDescription' => $user->can('editDescription', $ticket),
            'changeStatus' => $allowed !== [],
            'changePriority' => $user->can('changePriority', $ticket),
            'claim' => $user->can('claim', $ticket) && $ticket->assigned_agent_id === null,
            'assign' => $user->can('assign', $ticket),
            'assignOutsideCategory' => $user->can('assignOutsideCategory', $ticket),
            'reply' => $user->can('reply', $ticket),
            'addInternalNote' => $user->can('addInternalNote', $ticket),
            'allowedStatuses' => array_map(static fn (TicketStatus $s): string => $s->value, $allowed),
        ];
    }

    public static function seesEscalation(Request $request): bool
    {
        $viewer = $request->user();

        return $viewer instanceof User && $viewer->can(Permission::TicketsViewAny->value);
    }
}
