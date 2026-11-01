<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Tickets\ChangeTicketPriority;
use App\Actions\Tickets\ChangeTicketStatus;
use App\Actions\Tickets\CreateTicket;
use App\Actions\Tickets\UpdateTicket;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tickets\ChangePriorityRequest;
use App\Http\Requests\Tickets\ChangeStatusRequest;
use App\Http\Requests\Tickets\ListRequest;
use App\Http\Requests\Tickets\StoreRequest;
use App\Http\Requests\Tickets\UpdateRequest;
use App\Http\Resources\V1\ActivityResource;
use App\Http\Resources\V1\AttachmentResource;
use App\Http\Resources\V1\TicketListResource;
use App\Http\Resources\V1\TicketReplyResource;
use App\Http\Resources\V1\TicketResource;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tickets\TicketListQuery;
use App\Services\Tickets\TicketPageData;
use App\Services\Tickets\TicketQueueCounts;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

final class TicketController extends Controller
{
    public function __construct(
        private readonly TicketPageData $pages,
    ) {}

    public function index(ListRequest $request, TicketListQuery $tickets, TicketQueueCounts $queues): InertiaResponse|RedirectResponse
    {
        $user = $this->user($request);
        $default = $request->query->count() === 0 ? $this->pages->defaultListQuery($user) : null;

        if ($default !== null) {
            return to_route('tickets.index', $default);
        }

        $validated = $request->validated();
        $scope = $request->scope();

        return Inertia::render('tickets/Index', [
            'tickets' => fn () => TicketListResource::collection($tickets->paginate($user, $validated, CarbonImmutable::now())),
            'scope' => $scope,
            'filters' => (object) $validated,
            'filterOptions' => fn (): array => $this->pages->filterOptions($user, $scope),
            'queues' => fn (): array => $queues->for($user),
        ]);
    }

    public function create(Request $request): InertiaResponse
    {
        Gate::authorize('create', Ticket::class);

        return Inertia::render('tickets/Create', [
            'options' => $this->pages->formOptions(),
            'canCreateOnBehalf' => $this->user($request)->can('createOnBehalf', Ticket::class),
            'attachmentRules' => $this->pages->attachmentRules(),
        ]);
    }

    public function store(StoreRequest $request, CreateTicket $createTicket): RedirectResponse
    {
        $ticket = $createTicket->handle($this->user($request), $request->actionData(), $request->attachmentFiles());

        return to_route('tickets.show', $ticket)->with('success', "Ticket {$ticket->number} created.");
    }

    public function show(Request $request, Ticket $ticket): InertiaResponse
    {
        Gate::authorize('view', $ticket);

        $user = $this->user($request);
        $repliesPages = $this->pages->clampPages($request->integer('replies_pages', 1));
        $activitiesPages = $this->pages->clampPages($request->integer('activities_pages', 1));
        $loaded = $this->memo(fn (): Ticket => $this->pages->loadForShow($ticket, $user));
        $ticketData = $this->memo(fn (): array => TicketResource::make($loaded())->resolve($request));
        $can = fn (): array => (array) ($ticketData()['can'] ?? []);
        $replies = $this->memo(fn (): array => $this->pages->replies($ticket, $user, $repliesPages));
        $activities = $this->memo(fn (): array => $this->pages->activities($ticket, $user, $activitiesPages));

        return Inertia::render('tickets/Show', [
            'ticket' => $ticketData,
            'replies' => fn (): array => TicketReplyResource::collection($replies()['items'])->resolve($request),
            'repliesHasMore' => fn (): bool => $replies()['has_more'],
            'repliesPages' => $repliesPages,
            'activitiesPages' => $activitiesPages,
            'attachments' => fn (): array => AttachmentResource::collection($loaded()->attachments)->resolve($request),
            'activities' => fn (): array => ActivityResource::collection($activities()['items'])->resolve($request),
            'activitiesHasMore' => fn (): bool => $activities()['has_more'],
            'agents' => fn (): array => ($can()['assign'] ?? false) === true ? $this->pages->agentOptions() : [],
            'can' => $can,
            'options' => fn (): array => $this->pages->formOptions(),
            'attachmentRules' => fn (): array => $this->pages->attachmentRules(),
        ]);
    }

    public function update(UpdateRequest $request, Ticket $ticket, UpdateTicket $updateTicket): RedirectResponse
    {
        $updateTicket->handle($this->user($request), $ticket, $request->validated());

        return back()->with('success', 'Ticket updated.');
    }

    public function status(ChangeStatusRequest $request, Ticket $ticket, ChangeTicketStatus $changeStatus): RedirectResponse
    {
        $ticket = $changeStatus->handle($this->user($request), $ticket, $request->targetStatus());

        return back()->with('success', "Status changed to {$ticket->status->label()}.");
    }

    public function priority(ChangePriorityRequest $request, Ticket $ticket, ChangeTicketPriority $changePriority): RedirectResponse
    {
        $ticket = $changePriority->handle($this->user($request), $ticket, $request->targetPriority());

        return back()->with('success', "Priority changed to {$ticket->priority->label()}.");
    }

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $resolve
     * @return Closure(): TValue
     */
    private function memo(Closure $resolve): Closure
    {
        $resolved = false;
        $value = null;

        return static function () use ($resolve, &$resolved, &$value): mixed {
            if (! $resolved) {
                $value = $resolve();
                $resolved = true;
            }

            return $value;
        };
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
