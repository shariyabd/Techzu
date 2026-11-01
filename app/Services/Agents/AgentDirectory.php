<?php

declare(strict_types=1);

namespace App\Services\Agents;

use App\Enums\Role;
use App\Enums\SlaState;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tickets\ResponseQuery;
use App\Services\Tickets\TicketDataCache;
use App\Services\Tickets\TicketListQuery;
use App\Services\Users\UserDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;

final class AgentDirectory
{
    public const array SORTS = [
        'name' => 'Name (A–Z)',
        '-name' => 'Name (Z–A)',
        '-active' => 'Most active tickets',
        '-unresponded' => 'Most unresponded',
        '-breached' => 'Most SLA breaches',
    ];

    public const string DEFAULT_SORT = 'name';

    public const array ROLE_FILTERS = [
        'agent' => 'Agents',
        'admin' => 'Admins',
        'all' => 'All staff',
    ];

    public const string DEFAULT_ROLE = 'agent';

    public const array AVAILABILITY_FILTERS = [
        'available' => 'Available',
        'unavailable' => 'Unavailable',
    ];

    public const array STATS = [
        'total_assigned_count',
        'active_count',
        'unresponded_count',
        'awaiting_reply_count',
        'breached_count',
        'resolved_recent_count',
    ];

    public const array TABS = [
        'active' => 'Active',
        'unresponded' => 'Unresponded',
        'awaiting' => 'Awaiting reply',
        'breached' => 'SLA breached',
        'resolved' => 'Resolved',
        'all' => 'All assigned',
    ];

    public const string DEFAULT_TAB = 'active';

    public const int LIST_PAGE_SIZE = 25;

    public const int LIST_MAX_ROWS = 100;

    private const array LIST_COLUMNS = [
        'users.id', 'users.name', 'users.email', 'users.avatar_path', 'users.is_available', 'users.last_seen_at',
    ];

    private const array RELATIONS = ['roles:id,name', 'categories:id,name'];

    private const array SORT_COLUMNS = [
        'name' => 'users.name',
        'active' => 'active_count',
        'unresponded' => 'unresponded_count',
        'breached' => 'breached_count',
    ];

    private const int TICKETS_PER_PAGE = 15;

    private const string SUMMARY_CACHE_KEY = 'agents:directory-summary';

    private const string SORT_CACHE_KEY = 'agents:directory-sort-stats';

    public function __construct(
        private readonly Config $config,
        private readonly UserDirectory $users,
        private readonly TicketListQuery $tickets,
        private readonly TicketDataCache $cache,
        private readonly AgentDirectoryVersion $version,
    ) {}

    /**
     * @param  array{q: string|null, role: string, availability: string|null, category_id: int|null, sort: string, per_page: int}  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(array $filters, CarbonImmutable $now, ?int $page = null): LengthAwarePaginator
    {
        $query = $this->filtered($filters);
        $total = (clone $query)->count();

        $this->sort($query, array_key_exists($filters['sort'], self::SORTS) ? $filters['sort'] : self::DEFAULT_SORT, $now);

        $paginator = $query->paginate($filters['per_page'], ['*'], 'page', $page, $total)->withQueryString();
        $this->attachStats($paginator->getCollection(), $now);

        return $paginator;
    }

    /**
     * @param  array{q: string|null, role: string, availability: string|null, category_id: int|null, sort: string, per_page: int}  $filters
     * @return array{agents: LengthAwarePaginator<int, User>, has_more: bool}
     */
    public function browse(array $filters, int $pages, CarbonImmutable $now): array
    {
        $pages = $this->clampListPages($pages);
        $agents = $this->paginate([...$filters, 'per_page' => $pages * self::LIST_PAGE_SIZE], $now, 1);

        return [
            'agents' => $agents,
            'has_more' => $agents->hasMorePages() && $pages < $this->clampListPages(PHP_INT_MAX),
        ];
    }

    public function clampListPages(int $pages): int
    {
        return max(1, min($pages, intdiv(self::LIST_MAX_ROWS, self::LIST_PAGE_SIZE)));
    }

    public function profile(User $agent, CarbonImmutable $now): User
    {
        $profile = User::query()->select(self::LIST_COLUMNS)->with(self::RELATIONS)->whereKey($agent->getKey())->firstOrFail();
        $this->attachStats(new Collection([$profile]), $now);

        return $profile;
    }

    /** @return LengthAwarePaginator<int, Ticket> */
    public function tickets(User $viewer, User $agent, string $tab, CarbonImmutable $now): LengthAwarePaginator
    {
        return $this->tickets->paginate($viewer, [
            ...$this->tabFilters($tab, $now),
            'assigned_agent_id' => $agent->id,
            'sort' => '-updated_at',
            'per_page' => self::TICKETS_PER_PAGE,
        ], $now, withLatestMessage: true);
    }

    /** @return array<string, mixed> */
    public function tabFilters(string $tab, CarbonImmutable $now): array
    {
        return match ($tab) {
            'unresponded' => ['scope' => 'active', 'response' => ResponseQuery::UNRESPONDED],
            'awaiting' => ['scope' => 'active', 'response' => ResponseQuery::AWAITING],
            'breached' => ['scope' => 'active', 'sla_state' => SlaState::Breached->value],
            'resolved' => ['scope' => 'resolved', 'resolved_since' => $this->resolvedSince($now)],
            'all' => ['scope' => 'all'],
            default => ['scope' => 'active'],
        };
    }

    /** @return array{agents: int, available: int, unresponded: int, breached: int} */
    public function summary(CarbonImmutable $now): array
    {
        return $this->cache->remember(self::SUMMARY_CACHE_KEY, $this->cacheTtl(), fn (): array => $this->computeSummary($now), $this->version->current());
    }

    /** @return list<array{id: int, name: string, is_active: bool}> */
    public function categoryOptions(): array
    {
        return $this->users->categoryOptions();
    }

    public function resolvedWindowDays(): int
    {
        return max(1, (int) $this->config->get('tickets.dashboard.resolved_window_days', 30));
    }

    public function resolvedSince(CarbonImmutable $now): CarbonImmutable
    {
        return $now->subDays($this->resolvedWindowDays());
    }

    /**
     * @param  array<string, string>  $labels
     * @return list<array{value: string, label: string}>
     */
    public static function options(array $labels): array
    {
        return array_map(
            static fn (string $value, string $label): array => ['value' => $value, 'label' => $label],
            array_keys($labels),
            array_values($labels),
        );
    }

    /**
     * @param  array{q: string|null, role: string, availability: string|null, category_id: int|null, sort: string, per_page: int}  $filters
     * @return Builder<User>
     */
    private function filtered(array $filters): Builder
    {
        $prefix = $filters['q'] === null ? null : addcslashes($filters['q'], '%_\\').'%';
        $availability = $filters['availability'];
        $categoryId = $filters['category_id'];

        return User::query()
            ->select(self::LIST_COLUMNS)
            ->with(self::RELATIONS)
            ->role($this->roles($filters['role']))
            ->when($prefix !== null, static function (Builder $query) use ($prefix): void {
                $query->where(static function (Builder $match) use ($prefix): void {
                    $match->where('users.name', 'like', $prefix)->orWhere('users.email', 'like', $prefix);
                });
            })
            ->when($availability !== null, static function (Builder $query) use ($availability): void {
                $query->where('users.is_available', $availability === 'available');
            })
            ->when($categoryId !== null, static function (Builder $query) use ($categoryId): void {
                $query->whereHas('categories', static fn (Builder $categories): Builder => $categories->whereKey($categoryId));
            });
    }

    /** @return list<string> */
    private function roles(string $filter): array
    {
        return match ($filter) {
            'admin' => [Role::Admin->value],
            'all' => [Role::Agent->value, Role::Admin->value],
            default => [Role::Agent->value],
        };
    }

    /** @param  Builder<User>  $query */
    private function sort(Builder $query, string $sort, CarbonImmutable $now): void
    {
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = self::SORT_COLUMNS[ltrim($sort, '-')];

        if ($column === 'users.name') {
            $query->orderBy('users.name', $direction)->orderBy('users.id');

            return;
        }

        $cases = '';
        $bindings = [];

        foreach ($this->sortStats($now) as $row) {
            if ($row[$column] > 0) {
                $cases .= 'WHEN ? THEN ? ';
                array_push($bindings, $row['agent_id'], $row[$column]);
            }
        }

        if ($bindings !== []) {
            $query->orderByRaw("CASE users.id {$cases}ELSE 0 END {$direction}", $bindings);
        }

        $query->orderBy('users.name')->orderBy('users.id');
    }

    /** @return array{agents: int, available: int, unresponded: int, breached: int} */
    private function computeSummary(CarbonImmutable $now): array
    {
        $row = (array) User::query()
            ->role(Role::Agent->value)
            ->leftJoinSub($this->activeStats($now), 'agent_stats', 'agent_stats.agent_id', '=', 'users.id')
            ->toBase()
            ->selectRaw(implode(', ', [
                'COUNT(*) AS agents',
                'COALESCE(SUM(users.is_available), 0) AS available',
                'COALESCE(SUM(agent_stats.unresponded_count), 0) AS unresponded',
                'COALESCE(SUM(agent_stats.breached_count), 0) AS breached',
            ]))
            ->first();

        return [
            'agents' => (int) ($row['agents'] ?? 0),
            'available' => (int) ($row['available'] ?? 0),
            'unresponded' => (int) ($row['unresponded'] ?? 0),
            'breached' => (int) ($row['breached'] ?? 0),
        ];
    }

    /** @return list<array{agent_id: int, active_count: int, unresponded_count: int, breached_count: int}> */
    private function sortStats(CarbonImmutable $now): array
    {
        return $this->cache->remember(self::SORT_CACHE_KEY, $this->cacheTtl(), fn (): array => $this->activeStats($now)
            ->get()
            ->map(static fn (object $row): array => [
                'agent_id' => (int) $row->agent_id,
                'active_count' => (int) $row->active_count,
                'unresponded_count' => (int) $row->unresponded_count,
                'breached_count' => (int) $row->breached_count,
            ])
            ->values()
            ->all(), $this->version->current());
    }

    private function cacheTtl(): int
    {
        return max(1, (int) $this->config->get('tickets.agent_directory.cache_ttl_seconds', 60));
    }

    /** @param  Collection<int, User>  $users */
    private function attachStats(Collection $users, CarbonImmutable $now): void
    {
        if ($users->isEmpty()) {
            return;
        }

        $rows = $this->ticketStats($now, $users->modelKeys())->get()->keyBy('agent_id');

        foreach ($users as $user) {
            $row = (array) ($rows->get($user->id) ?? []);

            foreach (self::STATS as $column) {
                $user->setAttribute($column, (int) ($row[$column] ?? 0));
            }
        }
    }

    private function activeStats(CarbonImmutable $now): QueryBuilder
    {
        $unresponded = ResponseQuery::unrespondedSql();

        return Ticket::query()->toBase()
            ->selectRaw('tickets.assigned_agent_id AS agent_id')
            ->selectRaw(implode(', ', [
                'COUNT(*) AS active_count',
                "SUM(CASE WHEN {$unresponded} THEN 1 ELSE 0 END) AS unresponded_count",
                'SUM(CASE WHEN tickets.sla_paused_at IS NULL AND tickets.sla_due_at <= ? THEN 1 ELSE 0 END) AS breached_count',
            ]), [$now])
            ->whereIn('tickets.status', self::values(TicketStatus::active()))
            ->whereNotNull('tickets.assigned_agent_id')
            ->groupBy('tickets.assigned_agent_id');
    }

    /** @param  list<int>  $agentIds */
    private function ticketStats(CarbonImmutable $now, array $agentIds): QueryBuilder
    {
        $active = self::values(TicketStatus::active());
        $finished = self::values(TicketStatus::finished());
        $activeIn = implode(', ', array_fill(0, count($active), '?'));
        $finishedIn = implode(', ', array_fill(0, count($finished), '?'));
        $resolvedSince = $this->resolvedSince($now);
        $unresponded = ResponseQuery::unrespondedSql();
        $awaiting = ResponseQuery::awaitingSql();

        return Ticket::query()->toBase()
            ->selectRaw('tickets.assigned_agent_id AS agent_id')
            ->selectRaw(implode(', ', [
                'COUNT(*) AS total_assigned_count',
                "SUM(CASE WHEN tickets.status IN ({$activeIn}) THEN 1 ELSE 0 END) AS active_count",
                "SUM(CASE WHEN tickets.status IN ({$activeIn}) THEN CASE WHEN {$unresponded} THEN 1 ELSE 0 END ELSE 0 END) AS unresponded_count",
                "SUM(CASE WHEN tickets.status IN ({$activeIn}) THEN CASE WHEN {$awaiting} THEN 1 ELSE 0 END ELSE 0 END) AS awaiting_reply_count",
                "SUM(CASE WHEN tickets.status IN ({$activeIn}) AND tickets.sla_paused_at IS NULL AND tickets.sla_due_at <= ? THEN 1 ELSE 0 END) AS breached_count",
                "SUM(CASE WHEN tickets.status IN ({$finishedIn}) AND tickets.resolved_at >= ? THEN 1 ELSE 0 END) AS resolved_recent_count",
            ]), [
                ...$active,
                ...$active,
                ...$active,
                ...$active, $now,
                ...$finished, $resolvedSince,
            ])
            ->whereIn('tickets.assigned_agent_id', $agentIds)
            ->groupBy('tickets.assigned_agent_id');
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
