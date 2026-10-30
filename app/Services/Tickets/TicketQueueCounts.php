<?php

declare(strict_types=1);

namespace App\Services\Tickets;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class TicketQueueCounts
{
    /** @return array{active: array{open: int, total: int}, assigned_to_me: array{open: int, total: int}|null, unassigned: array{open: int, total: int}|null, resolved: array{open: int, total: int}, categories: list<array{category_id: int, open: int, total: int}>} */
    public function for(User $viewer): array
    {
        $isStaff = $viewer->isStaff();

        $rows = Ticket::query()
            ->visibleTo($viewer)
            ->selectRaw(
                'tickets.status, tickets.category_id, CASE WHEN tickets.assigned_agent_id IS NULL THEN 1 WHEN tickets.assigned_agent_id = ? THEN 2 ELSE 0 END AS bucket, COUNT(*) AS aggregate',
                [$viewer->id],
            )
            ->groupBy('tickets.status', 'tickets.category_id', DB::raw('bucket'))
            ->toBase()
            ->get();

        $active = $this->empty();
        $resolved = $this->empty();
        $mine = $this->empty();
        $unassigned = $this->empty();
        $categories = [];

        foreach ($rows as $row) {
            $status = TicketStatus::from((string) $row->status);
            $count = (int) $row->aggregate;
            $isOpen = $status === TicketStatus::Open;

            if (! $status->isActive()) {
                $this->add($resolved, $count, $isOpen);

                continue;
            }

            $this->add($active, $count, $isOpen);

            if (! $isStaff) {
                continue;
            }

            if ((int) $row->bucket === 2) {
                $this->add($mine, $count, $isOpen);
            }

            if ((int) $row->bucket === 1) {
                $this->add($unassigned, $count, $isOpen);
            }

            $categoryId = (int) $row->category_id;
            $categories[$categoryId] ??= $this->empty();
            $this->add($categories[$categoryId], $count, $isOpen);
        }

        return [
            'active' => $active,
            'assigned_to_me' => $isStaff ? $mine : null,
            'unassigned' => $isStaff ? $unassigned : null,
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
