<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Assignment\AssignTicket;
use App\Actions\Assignment\ClaimTicket;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assignment\AssignRequest;
use App\Http\Resources\V1\TicketResource;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tickets\TicketPageData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class AssignmentController extends Controller
{
    public function __construct(
        private readonly TicketPageData $pages,
    ) {}

    public function claim(Request $request, Ticket $ticket, ClaimTicket $claimTicket): TicketResource
    {
        Gate::authorize('claim', $ticket);

        return TicketResource::make($this->pages->loadSummary($claimTicket->handle($this->user($request), $ticket)));
    }

    public function assign(AssignRequest $request, Ticket $ticket, AssignTicket $assignTicket): TicketResource
    {
        $ticket = $assignTicket->handle($this->user($request), $ticket, $request->targetAgent());

        return TicketResource::make($this->pages->loadSummary($ticket));
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
