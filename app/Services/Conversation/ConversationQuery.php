<?php

declare(strict_types=1);

namespace App\Services\Conversation;

use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ConversationQuery
{
    /** @return LengthAwarePaginator<int, TicketReply> */
    public function paginateFor(Ticket $ticket, User $viewer, int $perPage): LengthAwarePaginator
    {
        $seesInternal = $viewer->can('viewInternal', $ticket);

        $paginator = $ticket->replies()
            ->visibleTo($viewer)
            ->with([
                'author:id,name',
                'attachments' => static function (HasMany $query) use ($seesInternal): void {
                    $query->select(['id', 'ticket_id', 'reply_id', 'original_name', 'mime_type', 'size_bytes', 'is_internal', 'created_at'])
                        ->orderBy('id');

                    if (! $seesInternal) {
                        $query->where('is_internal', false);
                    }
                },
            ])
            ->oldest()
            ->oldest('id')
            ->paginate(max(1, min($perPage, (int) config('tickets.per_page.max', 100))))
            ->withQueryString();

        $paginator->getCollection()->each(static fn (TicketReply $reply): TicketReply => $reply->setRelation('ticket', $ticket));

        return $paginator;
    }
}
