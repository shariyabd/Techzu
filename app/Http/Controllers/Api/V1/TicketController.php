<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

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
use App\Http\Resources\V1\TicketListResource;
use App\Http\Resources\V1\TicketResource;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tickets\TicketListQuery;
use App\Services\Tickets\TicketPageData;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

final class TicketController extends Controller
{
    public function __construct(
        private readonly TicketPageData $pages,
    ) {}

    public function index(ListRequest $request, TicketListQuery $tickets): AnonymousResourceCollection
    {
        return TicketListResource::collection(
            $tickets->paginate($this->user($request), $request->validated(), CarbonImmutable::now()),
        );
    }

    public function store(StoreRequest $request, CreateTicket $createTicket): JsonResponse
    {
        $ticket = $createTicket->handle($this->user($request), $request->actionData(), $request->attachmentFiles());

        return TicketResource::make($this->pages->loadSummary($ticket))->response()->setStatusCode(201);
    }

    public function show(Request $request, Ticket $ticket): TicketResource
    {
        Gate::authorize('view', $ticket);

        return TicketResource::make($this->pages->loadForShow($ticket, $this->user($request)))->withAttachments();
    }

    public function update(UpdateRequest $request, Ticket $ticket, UpdateTicket $updateTicket): TicketResource
    {
        $ticket = $updateTicket->handle($this->user($request), $ticket, $request->validated());

        return TicketResource::make($this->pages->loadSummary($ticket));
    }

    public function status(ChangeStatusRequest $request, Ticket $ticket, ChangeTicketStatus $changeStatus): TicketResource
    {
        $ticket = $changeStatus->handle($this->user($request), $ticket, $request->targetStatus());

        return TicketResource::make($this->pages->loadSummary($ticket));
    }

    public function priority(ChangePriorityRequest $request, Ticket $ticket, ChangeTicketPriority $changePriority): TicketResource
    {
        $ticket = $changePriority->handle($this->user($request), $ticket, $request->targetPriority());

        return TicketResource::make($this->pages->loadSummary($ticket));
    }

    public function activities(Request $request, Ticket $ticket): AnonymousResourceCollection
    {
        Gate::authorize('view', $ticket);

        $perPage = $request->integer('per_page', (int) config('tickets.per_page.default', 20));

        return ActivityResource::collection($this->pages->activitiesPage($ticket, $this->user($request), $perPage));
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
