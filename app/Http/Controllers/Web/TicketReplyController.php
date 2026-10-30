<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Conversation\AddReply;
use App\Http\Controllers\Controller;
use App\Http\Requests\Conversation\StoreRequest;
use App\Models\Ticket;
use Illuminate\Http\RedirectResponse;

final class TicketReplyController extends Controller
{
    public function store(Ticket $ticket, StoreRequest $request, AddReply $addReply): RedirectResponse
    {
        $reply = $addReply->handle($request->actor(), $ticket, $request->replyBody(), $request->wantsInternal(), $request->attachmentFiles());

        return to_route('tickets.show', $ticket)
            ->with('success', $reply->is_internal ? 'Internal note added.' : 'Reply sent.');
    }
}
