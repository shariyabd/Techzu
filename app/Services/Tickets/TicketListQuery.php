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

    public function __construct(
        private readonly Config $config,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     * @return LengthAwarePaginator<int, Ticket>
     */
    public function paginate(User $viewer, array $validated, CarbonImmutable $now): LengthAwarePaginator
    {
        $statuses = ($validated['scope'] ?? 'active') === 'resolved' ? TicketStatus::finished() : TicketStatus::active();

        $query = Ticket::query()
            ->visibleTo($viewer)
            ->select(self::LIST_COLUMNS)
            ->whereIn('tickets.status', array_map(static fn (TicketStatus $s): string => $s->value, $statuses))
            ->with(['category:id,name,slug,is_active', 'customer:id,name', 'assignedAgent:id,name'])
            ->withCount(['replies' => static function (Builder $q) use ($viewer): void {
                /** @var Builder<TicketReply> $q */
                $q->visibleTo($viewer);
            }]);

        $query = $this->applyFilters($query, $validated, $viewer, $now);

        return $query->paginate($this->perPage($validated))->withQueryString();
    }

    /**
     * @param  Builder<Ticket>  $query
     * @param  array<string, mixed>  $validated
     * @return Builder<Ticket>
     */
    private function applyFilters(Builder $query, array $validated, User $viewer, CarbonImmutable $now): Builder
    {
        return $query->orderByDesc('tickets.created_at')->orderByDesc('tickets.id');
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
