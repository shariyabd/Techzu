<?php

declare(strict_types=1);

namespace App\Services\Tickets;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class TicketListQuery
{
    private const LIST_COLUMNS = [
        'tickets.id', 'tickets.number', 'tickets.subject', 'tickets.status', 'tickets.priority',
        'tickets.category_id', 'tickets.customer_id', 'tickets.assigned_agent_id',
        'tickets.sla_started_at', 'tickets.sla_due_at', 'tickets.sla_warning_at', 'tickets.sla_paused_at',
        'tickets.escalation_level', 'tickets.resolved_at', 'tickets.closed_at',
        'tickets.created_at', 'tickets.updated_at',
    ];

    private const int PREVIEW_LENGTH = 200;

    public function __construct(
        private readonly Config $config,
        private readonly TicketFilter $filter,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     * @return LengthAwarePaginator<int, Ticket>
     */
    public function paginate(User $viewer, array $validated, CarbonImmutable $now, bool $withLatestMessage = false): LengthAwarePaginator
    {
        $statuses = TicketStatus::forScope((string) ($validated['scope'] ?? 'active'));

        $query = Ticket::query()
            ->select(self::LIST_COLUMNS)
            ->whereIn('tickets.status', array_map(static fn (TicketStatus $s): string => $s->value, $statuses))
            ->with(['category:id,name,slug,is_active', 'customer:id,name,avatar_path,last_seen_at', 'assignedAgent:id,name,avatar_path,last_seen_at'])
            ->withCount(['replies' => static function (Builder $q) use ($viewer): void {
                /** @var Builder<TicketReply> $q */
                $q->visibleTo($viewer);
            }])
            ->withExists(['replies as has_agent_response' => static function (Builder $q): void {
                $q->where('ticket_replies.is_internal', false)
                    ->whereColumn('ticket_replies.author_id', '!=', 'tickets.customer_id');
            }]);

        if ($withLatestMessage) {
            $this->selectLatestMessage($query);
        }

        return $this->filter->apply($query, $validated, $viewer, $now)
            ->paginate($this->perPage($validated))->withQueryString();
    }

    /** @param  Builder<Ticket>  $query */
    private function selectLatestMessage(Builder $query): void
    {
        $length = self::PREVIEW_LENGTH;

        $query->selectSub(
            TicketReply::query()
                ->selectRaw("SUBSTRING(ticket_replies.body, 1, {$length})")
                ->whereColumn('ticket_replies.ticket_id', 'tickets.id')
                ->where('ticket_replies.is_internal', false)
                ->orderByDesc('ticket_replies.created_at')
                ->orderByDesc('ticket_replies.id')
                ->limit(1),
            'latest_reply_preview',
        )->selectRaw("SUBSTRING(tickets.description, 1, {$length}) AS description_preview");
    }

    /** @param  array<string, mixed>  $validated */
    private function perPage(array $validated): int
    {
        $default = (int) $this->config->get('tickets.per_page.default', 20);
        $max = (int) $this->config->get('tickets.per_page.max', 100);
        $requested = isset($validated['per_page']) ? (int) $validated['per_page'] : $default;

        return max(1, min($requested, $max));
    }
}
