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

    public function index(ListRequest $request, TicketListQuery $tickets, TicketQueueCounts $queues): InertiaResponse
    {
        $validated = $request->validated();

        return Inertia::render('tickets/Index', [
            'tickets' => TicketListResource::collection($tickets->paginate($this->user($request), $validated, CarbonImmutable::now())),
            'scope' => $request->scope(),
            'filters' => (object) $validated,
            'filterOptions' => $this->pages->filterOptions($this->user($request), $request->scope()),
            'queues' => $queues->for($this->user($request)),
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
        $this->pages->loadForShow($ticket, $user);
        $can = TicketResource::abilities($user, $ticket);
        $repliesPages = $this->pages->clampPages($request->integer('replies_pages', 1));
        $activitiesPages = $this->pages->clampPages($request->integer('activities_pages', 1));
        $replies = $this->pages->replies($ticket, $user, $repliesPages);
        $activities = $this->pages->activities($ticket, $user, $activitiesPages);

        return Inertia::render('tickets/Show', [
            'ticket' => TicketResource::make($ticket)->resolve($request),
            'replies' => TicketReplyResource::collection($replies['items'])->resolve($request),
            'repliesHasMore' => $replies['has_more'],
            'repliesPages' => $repliesPages,
            'activitiesPages' => $activitiesPages,
            'attachments' => AttachmentResource::collection($ticket->attachments)->resolve($request),
            'activities' => ActivityResource::collection($activities['items'])->resolve($request),
            'activitiesHasMore' => $activities['has_more'],
            'agents' => $can['assign'] ? $this->pages->agentOptions() : [],
            'can' => $can,
            'options' => $this->pages->formOptions(),
            'attachmentRules' => $this->pages->attachmentRules(),
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

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
