<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Enums\Role;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Sla\SlaQuery;
use App\Services\Sla\SlaSettingsRepository;
use App\Services\Tickets\TicketDataCache;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;

final class DashboardStats
{
    public const CACHE_KEY = 'dashboard:staff';

    private const SLA_HEALTH_KEYS = ['within', 'approaching', 'breached', 'paused'];

    public function __construct(
        private readonly TicketDataCache $cache,
        private readonly SlaSettingsRepository $settings,
    ) {}

    /** @return array{totals: array<string, int>, sla_health: array<string, int>, by_status: list<array{status: string, count: int}>, by_priority: list<array{priority: string, count: int}>, by_agent: list<array{agent_id: int, name: string, avatar_url: string|null, active: int, breached: int}>, trend: list<array{date: string, created: int, resolved: int}>} */
    public function summary(): array
    {
        $timezone = $this->settings->timezone();

        return $this->cache->remember(self::CACHE_KEY, (int) config('tickets.dashboard.cache_ttl_seconds'), function () use ($timezone): array {
            $now = CarbonImmutable::now();
            $counts = $this->totals($now);
            $breakdown = $this->activeBreakdown();

            return [
                'totals' => array_diff_key($counts, array_flip(['within', 'paused'])),
                'sla_health' => array_combine(self::SLA_HEALTH_KEYS, array_map(static fn (string $key): int => $counts[$key], self::SLA_HEALTH_KEYS)),
                'by_status' => $breakdown['by_status'],
                'by_priority' => $breakdown['by_priority'],
                'by_agent' => $this->byAgent($now),
                'trend' => $this->trend($now, $timezone),
            ];
        }, $timezone);
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
                "SUM(CASE WHEN status IN ({$activeIn}) AND sla_paused_at IS NULL AND sla_warning_at > ? THEN 1 ELSE 0 END) AS within",
                "SUM(CASE WHEN status IN ({$activeIn}) AND sla_paused_at IS NOT NULL THEN 1 ELSE 0 END) AS paused",
                "SUM(CASE WHEN status IN ({$finishedIn}) AND resolved_at >= ? THEN 1 ELSE 0 END) AS resolved",
            ]), [
                ...$active,
                ...$active,
                ...$active, TicketPriority::Critical->value,
                ...$active, $now,
                ...$active, $now, $now,
                ...$active, $now,
                ...$active,
                ...$finished, $resolvedSince,
            ])
            ->where(static function (QueryBuilder $query) use ($active, $finished, $resolvedSince): void {
                $query->whereIn('status', $active)
                    ->orWhere(static function (QueryBuilder $query) use ($finished, $resolvedSince): void {
                        $query->whereIn('status', $finished)->where('resolved_at', '>=', $resolvedSince);
                    });
            })
            ->first();

        $keys = ['open', 'unassigned', 'critical', 'breached', 'approaching', 'resolved', 'within', 'paused'];

        return array_combine($keys, array_map(static fn (string $key): int => (int) ($row[$key] ?? 0), $keys));
    }

    /** @return array{by_status: list<array{status: string, count: int}>, by_priority: list<array{priority: string, count: int}>} */
    private function activeBreakdown(): array
    {
        $rows = Ticket::query()->toBase()
            ->select(['priority', 'status'])
            ->selectRaw('COUNT(*) AS ticket_count')
            ->whereIn('status', self::values(TicketStatus::active()))
            ->groupBy('priority', 'status')
            ->get();

        $byStatus = [];
        $byPriority = [];

        foreach ($rows as $row) {
            $count = (int) $row->ticket_count;
            $byStatus[(string) $row->status] = ($byStatus[(string) $row->status] ?? 0) + $count;
            $byPriority[(string) $row->priority] = ($byPriority[(string) $row->priority] ?? 0) + $count;
        }

        $priorities = TicketPriority::cases();
        usort($priorities, static fn (TicketPriority $a, TicketPriority $b): int => $b->rank() <=> $a->rank());

        return [
            'by_status' => array_map(
                static fn (TicketStatus $status): array => ['status' => $status->value, 'count' => $byStatus[$status->value] ?? 0],
                TicketStatus::active(),
            ),
            'by_priority' => array_map(
                static fn (TicketPriority $priority): array => ['priority' => $priority->value, 'count' => $byPriority[$priority->value] ?? 0],
                $priorities,
            ),
        ];
    }

    /** @return list<array{date: string, created: int, resolved: int}> */
    private function trend(CarbonImmutable $now, string $timezone): array
    {
        $days = max(1, (int) config('tickets.dashboard.trend_days'));
        $today = $now->setTimezone($timezone)->startOfDay();
        $starts = array_map(static fn (int $offset): CarbonImmutable => $today->subDays($offset), range($days - 1, 0));
        $bounds = array_map(
            static fn (CarbonImmutable $start): array => [$start->utc(), $start->addDay()->utc()],
            $starts,
        );
        $from = $bounds[0][0];
        $until = $bounds[$days - 1][1];

        $created = $this->dailyCounts('created_at', $bounds, static function (QueryBuilder $query) use ($from, $until): void {
            $query->where('created_at', '>=', $from)->where('created_at', '<', $until);
        });
        $resolved = $this->dailyCounts('resolved_at', $bounds, static function (QueryBuilder $query) use ($from, $until): void {
            $query->whereIn('status', self::values(TicketStatus::finished()))
                ->where('resolved_at', '>=', $from)
                ->where('resolved_at', '<', $until);
        });

        return array_map(
            static fn (int $day, CarbonImmutable $start): array => ['date' => $start->toDateString(), 'created' => $created[$day], 'resolved' => $resolved[$day]],
            array_keys($starts),
            $starts,
        );
    }

    /**
     * @param  'created_at'|'resolved_at'  $column
     * @param  list<array{CarbonImmutable, CarbonImmutable}>  $bounds
     * @param  Closure(QueryBuilder): void  $scope
     * @return list<int>
     */
    private function dailyCounts(string $column, array $bounds, Closure $scope): array
    {
        $sums = [];
        $bindings = [];

        foreach ($bounds as $day => [$start, $end]) {
            $sums[] = "SUM(CASE WHEN {$column} >= ? AND {$column} < ? THEN 1 ELSE 0 END) AS d{$day}";
            $bindings[] = $start;
            $bindings[] = $end;
        }

        $query = Ticket::query()->toBase()->selectRaw(implode(', ', $sums), $bindings);
        $scope($query);
        $row = (array) $query->first();

        return array_map(static fn (int $day): int => (int) ($row["d{$day}"] ?? 0), array_keys($bounds));
    }

    /** @return list<array{agent_id: int, name: string, avatar_url: string|null, active: int, breached: int}> */
    private function byAgent(CarbonImmutable $now): array
    {
        $active = self::values(TicketStatus::active());

        return User::query()
            ->select(['id', 'name', 'avatar_path'])
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
                'avatar_url' => $agent->avatarUrl(),
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
