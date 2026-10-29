<?php

declare(strict_types=1);

namespace App\Services\Tickets;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Activity;

final class TicketPageData
{
    public function loadSummary(Ticket $ticket): Ticket
    {
        return $ticket->loadMissing(['category:id,name,slug,is_active', 'customer:id,name', 'assignedAgent:id,name']);
    }

    public function loadForShow(Ticket $ticket, User $viewer): Ticket
    {
        $seesInternal = $viewer->can('viewInternal', $ticket);

        return $ticket->load([
            'category:id,name,slug,is_active',
            'customer:id,name',
            'assignedAgent:id,name',
            'replies' => static fn (HasMany $q) => $q->visibleTo($viewer)
                ->with([
                    'author:id,name',
                    'attachments' => static fn (HasMany $a) => $a->when(! $seesInternal, static fn ($b) => $b->where('is_internal', false))->orderBy('id'),
                ])
                ->orderBy('created_at')
                ->orderBy('id'),
            'attachments' => static fn (HasMany $q) => $q->whereNull('reply_id')
                ->when(! $seesInternal, static fn ($b) => $b->where('is_internal', false))
                ->orderBy('id'),
        ]);
    }

    /** @return Collection<int, Activity> */
    public function activities(Ticket $ticket, User $viewer, int $limit = 100): Collection
    {
        return $ticket->activitiesVisibleTo($viewer)
            ->with('causer:id,name')
            ->latest()
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /** @return LengthAwarePaginator<int, Activity> */
    public function activitiesPage(Ticket $ticket, User $viewer, int $perPage): LengthAwarePaginator
    {
        return $ticket->activitiesVisibleTo($viewer)
            ->with('causer:id,name')
            ->latest()
            ->latest('id')
            ->paginate(max(1, min($perPage, (int) config('tickets.per_page.max', 100))));
    }

    /** @return list<array{id: int, name: string}> */
    public function agentOptions(): array
    {
        return User::query()
            ->availableAgents()
            ->orderBy('users.name')
            ->limit(500)
            ->get(['users.id', 'users.name'])
            ->map(static fn (User $u): array => ['id' => $u->id, 'name' => $u->name])
            ->values()
            ->all();
    }

    /** @return array{statuses: list<array{value: string, label: string}>, priorities: list<array{value: string, label: string}>, categories: list<array{id: int, name: string}>} */
    public function formOptions(): array
    {
        return [
            'statuses' => array_map(
                static fn (TicketStatus $s): array => ['value' => $s->value, 'label' => $s->label()],
                TicketStatus::cases(),
            ),
            'priorities' => array_map(
                static fn (TicketPriority $p): array => ['value' => $p->value, 'label' => $p->label()],
                TicketPriority::cases(),
            ),
            'categories' => Category::query()
                ->active()
                ->orderBy('name')
                ->limit(500)
                ->get(['id', 'name'])
                ->map(static fn (Category $c): array => ['id' => $c->id, 'name' => $c->name])
                ->values()
                ->all(),
        ];
    }
}
