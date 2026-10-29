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
        return $ticket->loadMissing(['category:id,name,slug,is_active', 'customer:id,name', 'assignedAgent:id,name']);
    }

    public function loadForShow(Ticket $ticket, User $viewer): Ticket
    {
        $seesInternal = $viewer->can('viewInternal', $ticket);

        $ticket->load([
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

        $ticket->replies->each(static fn (TicketReply $reply): TicketReply => $reply->setRelation('ticket', $ticket));

        return $ticket;
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

    /** @return array{statuses: list<array{value: string, label: string}>, priorities: list<array{value: string, label: string}>, slaStates: list<array{value: string, label: string}>, sorts: list<array{value: string, label: string}>, categories: list<array{id: int, name: string}>, agents: list<array{id: int, name: string}>, showPeopleFilters: bool} */
    public function filterOptions(User $viewer, string $scope): array
    {
        $isStaff = $viewer->can('createOnBehalf', Ticket::class);
        $form = $this->formOptions();

        return [
            'statuses' => array_map(
                static fn (TicketStatus $s): array => ['value' => $s->value, 'label' => $s->label()],
                $scope === 'resolved' ? TicketStatus::finished() : TicketStatus::active(),
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
            'categories' => $form['categories'],
            'agents' => $isStaff
                ? User::query()
                    ->role(Role::Agent->value)
                    ->orderBy('users.name')
                    ->limit(500)
                    ->get(['users.id', 'users.name'])
                    ->map(static fn (User $u): array => ['id' => $u->id, 'name' => $u->name])
                    ->values()
                    ->all()
                : [],
            'showPeopleFilters' => $isStaff,
        ];
    }
}
