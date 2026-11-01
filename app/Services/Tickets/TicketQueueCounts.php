<?php

declare(strict_types=1);

namespace App\Services\Tickets;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Contracts\Config\Repository as Config;

final class TicketQueueCounts
{
    public function __construct(
        private readonly TicketDataCache $cache,
        private readonly Config $config,
    ) {}

    /** @return array{active: array{open: int, total: int}, assigned_to_me: array{open: int, total: int}|null, unassigned: array{open: int, total: int}|null, resolved: array{open: int, total: int}, categories: list<array{category_id: int, open: int, total: int}>} */
    public function for(User $viewer): array
    {
        $visibility = TicketVisibility::of($viewer);
        $scope = $visibility->scopeKey();

        $rows = $scope === null
            ? []
            : $this->cache->remember(
                'tickets:queue-counts:'.$scope,
                max(1, (int) $this->config->get('tickets.queue_counts.cache_ttl_seconds', 60)),
                fn (): array => $this->rows($viewer),
            );

        return $this->summarize($rows, $visibility);
    }

    /** @return list<array{status: string, category_id: int, agent_id: int|null, count: int}> */
    private function rows(User $viewer): array
    {
        return Ticket::query()
            ->visibleTo($viewer)
            ->select(['tickets.status', 'tickets.category_id', 'tickets.assigned_agent_id'])
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('tickets.status', 'tickets.category_id', 'tickets.assigned_agent_id')
            ->toBase()
            ->get()
            ->map(static fn (object $row): array => [
                'status' => (string) $row->status,
                'category_id' => (int) $row->category_id,
                'agent_id' => $row->assigned_agent_id === null ? null : (int) $row->assigned_agent_id,
                'count' => (int) $row->aggregate,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array{status: string, category_id: int, agent_id: int|null, count: int}>  $rows
     * @return array{active: array{open: int, total: int}, assigned_to_me: array{open: int, total: int}|null, unassigned: array{open: int, total: int}|null, resolved: array{open: int, total: int}, categories: list<array{category_id: int, open: int, total: int}>}
     */
    private function summarize(array $rows, TicketVisibility $visibility): array
    {
        $showsQueues = $visibility->seesAllTickets;
        $active = $this->empty();
        $resolved = $this->empty();
        $mine = $this->empty();
        $unassigned = $this->empty();
        $categories = [];

        foreach ($rows as $row) {
            $status = TicketStatus::from($row['status']);
            $count = $row['count'];
            $isOpen = $status === TicketStatus::Open;

            if (! $status->isActive()) {
                $this->add($resolved, $count, $isOpen);

                continue;
            }

            $this->add($active, $count, $isOpen);

            if (! $showsQueues) {
                continue;
            }

            if ($row['agent_id'] === $visibility->viewerId) {
                $this->add($mine, $count, $isOpen);
            }

            if ($row['agent_id'] === null) {
                $this->add($unassigned, $count, $isOpen);
            }

            $categories[$row['category_id']] ??= $this->empty();
            $this->add($categories[$row['category_id']], $count, $isOpen);
        }

        return [
            'active' => $active,
            'assigned_to_me' => $showsQueues ? $mine : null,
            'unassigned' => $showsQueues ? $unassigned : null,
            'resolved' => $resolved,
            'categories' => array_map(
                static fn (int $id, array $counts): array => ['category_id' => $id, ...$counts],
                array_keys($categories),
                $categories,
            ),
        ];
    }

    /** @return array{open: int, total: int} */
    private function empty(): array
    {
        return ['open' => 0, 'total' => 0];
    }

    /** @param array{open: int, total: int} $counts */
    private function add(array &$counts, int $count, bool $isOpen): void
    {
        $counts['total'] += $count;
        $counts['open'] += $isOpen ? $count : 0;
    }
}
