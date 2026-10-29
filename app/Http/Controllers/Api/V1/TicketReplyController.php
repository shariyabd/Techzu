<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Conversation\AddReply;
use App\Http\Controllers\Controller;
use App\Http\Requests\Conversation\IndexRepliesRequest;
use App\Http\Requests\Conversation\StoreReplyRequest;
use App\Http\Resources\V1\TicketReplyResource;
use App\Models\Ticket;
use App\Services\Conversation\ConversationQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class TicketReplyController extends Controller
{
    public function index(Ticket $ticket, IndexRepliesRequest $request, ConversationQuery $conversation): AnonymousResourceCollection
    {
        return TicketReplyResource::collection(
            $conversation->paginateFor($ticket, $request->actor(), $request->perPage()),
        );
    }

    public function store(Ticket $ticket, StoreReplyRequest $request, AddReply $addReply): JsonResponse
    {
        $reply = $addReply->handle($request->actor(), $ticket, $request->replyBody(), $request->wantsInternal(), $request->attachmentFiles());

        $reply->load([
            'author:id,name',
            'attachments:id,ticket_id,reply_id,original_name,mime_type,size_bytes,is_internal,created_at',
        ]);

        return TicketReplyResource::make($reply)->response()->setStatusCode(201);
    }
}
