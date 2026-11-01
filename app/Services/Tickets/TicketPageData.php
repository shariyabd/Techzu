<?php

declare(strict_types=1);

namespace App\Services\Tickets;

use App\Enums\Role;
use App\Enums\SlaState;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Activity;

final class TicketPageData
{
    public function loadSummary(Ticket $ticket): Ticket
    {
        return $ticket->loadMissing(['category:id,name,slug,is_active', 'customer:id,name,avatar_path,last_seen_at', 'creator:id,name,avatar_path,last_seen_at', 'assignedAgent:id,name,avatar_path,last_seen_at']);
    }

    public function loadForShow(Ticket $ticket, User $viewer): Ticket
    {
        return $ticket->load([
            'category:id,name,slug,is_active',
            'customer:id,name,avatar_path,last_seen_at',
            'creator:id,name,avatar_path,last_seen_at',
            'assignedAgent:id,name,avatar_path,last_seen_at',
            'attachments' => static fn (HasMany $q) => $q->whereNull('reply_id')->visibleTo($viewer)->orderBy('id'),
        ]);
    }

    public function clampPages(int $pages): int
    {
        return max(1, min($pages, max(1, (int) config('tickets.show.max_pages'))));
    }

    /** @return array{items: Collection<int, TicketReply>, has_more: bool} */
    public function replies(Ticket $ticket, User $viewer, int $pages): array
    {
        $limit = $this->showLimit($pages);
        $capped = $this->clampPages($pages) >= $this->clampPages(PHP_INT_MAX);

        $fetched = $ticket->replies()
            ->visibleTo($viewer)
            ->with([
                'author:id,name,avatar_path,last_seen_at',
                'attachments' => static fn (HasMany $a) => $a->visibleTo($viewer)->orderBy('id'),
            ])
            ->latest()
            ->latest('id')
            ->limit($limit + 1)
            ->get();

        $items = $fetched->take($limit)->reverse()->values();
        $items->each(static fn (TicketReply $reply): TicketReply => $reply->setRelation('ticket', $ticket));

        return ['items' => $items, 'has_more' => ! $capped && $fetched->count() > $limit];
    }

    /** @return array{items: Collection<int, Activity>, has_more: bool} */
    public function activities(Ticket $ticket, User $viewer, int $pages): array
    {
        $limit = $this->showLimit($pages);
        $capped = $this->clampPages($pages) >= $this->clampPages(PHP_INT_MAX);

        $fetched = $ticket->activitiesVisibleTo($viewer)
            ->with('causer:id,name,avatar_path,last_seen_at')
            ->latest()
            ->latest('id')
            ->limit($limit + 1)
            ->get();

        return ['items' => $fetched->take($limit)->values(), 'has_more' => ! $capped && $fetched->count() > $limit];
    }

    private function showLimit(int $pages): int
    {
        return $this->clampPages($pages) * max(1, (int) config('tickets.per_page.default', 20));
    }

    /** @return LengthAwarePaginator<int, Activity> */
    public function activitiesPage(Ticket $ticket, User $viewer, int $perPage): LengthAwarePaginator
    {
        return $ticket->activitiesVisibleTo($viewer)
            ->with('causer:id,name,avatar_path,last_seen_at')
            ->latest()
            ->latest('id')
            ->paginate(max(1, min($perPage, (int) config('tickets.per_page.max', 100))));
    }

    /** @return list<array{id: int, name: string, avatar_url: string|null, category_ids: list<int>}> */
    public function agentOptions(): array
    {
        return User::query()
            ->availableAgents()
            ->with('categories:categories.id')
            ->orderBy('users.name')
            ->limit(500)
            ->get(['users.id', 'users.name', 'users.avatar_path'])
            ->map(static fn (User $u): array => [
                'id' => $u->id,
                'name' => $u->name,
                'avatar_url' => $u->avatarUrl(),
                'category_ids' => $u->categories->map(static fn (Category $c): int => $c->id)->values()->all(),
            ])
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

    /** @return array{extensions: list<string>, max_kb: int, max_files: int} */
    public function attachmentRules(): array
    {
        /** @var list<string> $extensions */
        $extensions = config('attachments.extensions', []);

        return [
            'extensions' => $extensions,
            'max_kb' => (int) config('attachments.max_kb', 10240),
            'max_files' => (int) config('attachments.max_files', 5),
        ];
    }

    /** @return array{scope: string, assigned_agent_id: int}|null */
    public function defaultListQuery(User $viewer): ?array
    {
        if (! $viewer->hasRole(Role::Agent)) {
            return null;
        }

        return ['scope' => 'active', 'assigned_agent_id' => $viewer->id];
    }

    /** @return array{statuses: list<array{value: string, label: string}>, priorities: list<array{value: string, label: string}>, slaStates: list<array{value: string, label: string}>, sorts: list<array{value: string, label: string}>, responses: list<array{value: string, label: string}>, categories: list<array{id: int, name: string}>, agents: list<array{id: int, name: string, avatar_url: string|null}>, showPeopleFilters: bool} */
    public function filterOptions(User $viewer, string $scope): array
    {
        $isStaff = TicketVisibility::of($viewer)->seesAllTickets;
        $form = $this->formOptions();

        return [
            'statuses' => array_map(
                static fn (TicketStatus $s): array => ['value' => $s->value, 'label' => $s->label()],
                TicketStatus::forScope($scope),
            ),
            'priorities' => $form['priorities'],
            'slaStates' => array_map(
                static fn (SlaState $s): array => ['value' => $s->value, 'label' => $s->label()],
                SlaState::cases(),
            ),
            'sorts' => array_map(
                static fn (string $value, string $label): array => ['value' => $value, 'label' => $label],
                array_keys(TicketFilter::SORTS),
                array_values(TicketFilter::SORTS),
            ),
            'responses' => array_map(
                static fn (string $value, string $label): array => ['value' => $value, 'label' => $label],
                array_keys(ResponseQuery::FILTERS),
                array_values(ResponseQuery::FILTERS),
            ),
            'categories' => $form['categories'],
            'agents' => $isStaff
                ? User::query()
                    ->role(Role::Agent->value)
                    ->orderBy('users.name')
                    ->limit(500)
                    ->get(['users.id', 'users.name', 'users.avatar_path'])
                    ->map(static fn (User $u): array => ['id' => $u->id, 'name' => $u->name, 'avatar_url' => $u->avatarUrl()])
                    ->values()
                    ->all()
                : [],
            'showPeopleFilters' => $isStaff,
        ];
    }
}
