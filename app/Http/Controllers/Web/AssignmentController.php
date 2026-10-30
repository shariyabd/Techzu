<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Assignment\AssignTicket;
use App\Actions\Assignment\ClaimTicket;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assignment\AssignRequest;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class AssignmentController extends Controller
{
    public function claim(Request $request, Ticket $ticket, ClaimTicket $claimTicket): RedirectResponse
    {
        Gate::authorize('claim', $ticket);

        $claimTicket->handle($this->user($request), $ticket);

        return back()->with('success', "You claimed {$ticket->number}.");
    }

    public function assign(AssignRequest $request, Ticket $ticket, AssignTicket $assignTicket): RedirectResponse
    {
        $agent = $request->targetAgent();

        $assignTicket->handle($this->user($request), $ticket, $agent);

        return back()->with('success', "{$ticket->number} assigned to {$agent->name}.");
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
