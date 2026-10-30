<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Enums\Role;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Sla\SlaQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Cache;

final class DashboardStats
{
    public const CACHE_KEY = 'dashboard:staff';

    /** @return array{totals: array<string, int>, by_priority: list<array{priority: string, count: int}>, by_agent: list<array{agent_id: int, name: string, active: int, breached: int}>} */
    public function summary(): array
    {
        return Cache::remember(self::CACHE_KEY, (int) config('tickets.dashboard.cache_ttl_seconds'), function (): array {
            $now = CarbonImmutable::now();

            return [
                'totals' => $this->totals($now),
                'by_priority' => $this->byPriority(),
                'by_agent' => $this->byAgent($now),
            ];
        });
    }

    /** @return Collection<int, Ticket> */
    public function myTickets(User $viewer, int $limit = 5): Collection
    {
        return Ticket::query()
            ->select(['id', 'number', 'subject', 'status', 'priority', 'sla_due_at', 'sla_warning_at', 'sla_paused_at', 'escalation_level', 'created_at', 'updated_at'])
            ->where('assigned_agent_id', $viewer->id)
            ->whereIn('status', self::values(TicketStatus::active()))
            ->orderBy('sla_due_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /** @return array<string, int> */
    private function totals(CarbonImmutable $now): array
    {
        $active = self::values(TicketStatus::active());
        $finished = self::values(TicketStatus::finished());
        $activeIn = implode(', ', array_fill(0, count($active), '?'));
        $finishedIn = implode(', ', array_fill(0, count($finished), '?'));
        $resolvedSince = $now->subDays((int) config('tickets.dashboard.resolved_window_days'));

        $row = (array) Ticket::query()->toBase()
            ->selectRaw(implode(', ', [
                "SUM(CASE WHEN status IN ({$activeIn}) THEN 1 ELSE 0 END) AS open",
                "SUM(CASE WHEN status IN ({$activeIn}) AND assigned_agent_id IS NULL THEN 1 ELSE 0 END) AS unassigned",
                "SUM(CASE WHEN status IN ({$activeIn}) AND priority = ? THEN 1 ELSE 0 END) AS critical",
                "SUM(CASE WHEN status IN ({$activeIn}) AND sla_paused_at IS NULL AND sla_due_at <= ? THEN 1 ELSE 0 END) AS breached",
                "SUM(CASE WHEN status IN ({$activeIn}) AND sla_paused_at IS NULL AND sla_warning_at <= ? AND sla_due_at > ? THEN 1 ELSE 0 END) AS approaching",
                "SUM(CASE WHEN status IN ({$finishedIn}) AND resolved_at >= ? THEN 1 ELSE 0 END) AS resolved",
            ]), [
                ...$active,
                ...$active,
                ...$active, TicketPriority::Critical->value,
                ...$active, $now,
                ...$active, $now, $now,
                ...$finished, $resolvedSince,
            ])
            ->where(static function (QueryBuilder $query) use ($active, $finished, $resolvedSince): void {
                $query->whereIn('status', $active)
                    ->orWhere(static function (QueryBuilder $query) use ($finished, $resolvedSince): void {
                        $query->whereIn('status', $finished)->where('resolved_at', '>=', $resolvedSince);
                    });
            })
            ->first();

        $keys = ['open', 'unassigned', 'critical', 'breached', 'approaching', 'resolved'];

        return array_combine($keys, array_map(static fn (string $key): int => (int) ($row[$key] ?? 0), $keys));
    }

    /** @return list<array{priority: string, count: int}> */
    private function byPriority(): array
    {
        $counts = Ticket::query()->toBase()
            ->select('priority')
            ->selectRaw('COUNT(*) AS priority_count')
            ->whereIn('status', self::values(TicketStatus::active()))
            ->groupBy('priority')
            ->pluck('priority_count', 'priority');

        $priorities = TicketPriority::cases();
        usort($priorities, static fn (TicketPriority $a, TicketPriority $b): int => $b->rank() <=> $a->rank());

        return array_map(
            static fn (TicketPriority $priority): array => ['priority' => $priority->value, 'count' => (int) ($counts[$priority->value] ?? 0)],
            $priorities,
        );
    }

    /** @return list<array{agent_id: int, name: string, active: int, breached: int}> */
    private function byAgent(CarbonImmutable $now): array
    {
        $active = self::values(TicketStatus::active());

        return User::query()
            ->select(['id', 'name'])
            ->whereHas('roles', static fn (Builder $roles): Builder => $roles->where('name', Role::Agent->value))
            ->withCount([
                'assignedTickets as active_count' => static fn (Builder $tickets): Builder => $tickets->whereIn('status', $active),
                'assignedTickets as breached_count' => static fn (Builder $tickets): Builder => SlaQuery::whereBreached($tickets, $now),
            ])
            ->orderByDesc('active_count')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(static fn (User $agent): array => [
                'agent_id' => $agent->id,
                'name' => $agent->name,
                'active' => (int) $agent->getAttribute('active_count'),
                'breached' => (int) $agent->getAttribute('breached_count'),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<TicketStatus>  $statuses
     * @return list<string>
     */
    private static function values(array $statuses): array
    {
        return array_map(static fn (TicketStatus $status): string => $status->value, $statuses);
    }
}
