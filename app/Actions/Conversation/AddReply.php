<?php

declare(strict_types=1);

namespace App\Actions\Conversation;

use App\Actions\Tickets\ChangeTicketStatus;
use App\Enums\TicketStatus;
use App\Events\TicketReplied;
use App\Exceptions\Domain\TicketClosed;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Services\Attachments\AttachmentStorer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AddReply
{
    public function __construct(
        private readonly AttachmentStorer $attachments,
        private readonly ChangeTicketStatus $changeStatus,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     *
     * @throws TicketClosed
     */
    public function handle(User $author, Ticket $ticket, string $body, bool $isInternal, array $files = []): TicketReply
    {
        $isInternal = $isInternal && $author->can('addInternalNote', $ticket);
        /** @var Collection<int, Attachment> $stored */
        $stored = new Collection;

        try {
            return DB::transaction(function () use ($author, $ticket, $body, $isInternal, $files, &$stored): TicketReply {
                $current = Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();

                if ($current->status === TicketStatus::Closed) {
                    throw new TicketClosed(sprintf('Ticket %s is closed and no longer accepts replies.', $current->number));
                }

                $reply = new TicketReply(['body' => $body, 'is_internal' => $isInternal]);
                $reply->ticket()->associate($current);
                $reply->author()->associate($author);
                $reply->save();

                $stored = $this->attachments->storeMany($current, $reply, $author, $files, $isInternal);

                if (! $isInternal
                    && $author->id === $current->customer_id
                    && $current->status === TicketStatus::WaitingForCustomer) {
                    $current = $this->changeStatus->handle(null, $current, TicketStatus::InProgress);
                }

                $current->touch();

                TicketReplied::dispatch($current, $reply, $author);

                return $reply;
            });
        } catch (Throwable $exception) {
            $this->attachments->deleteFiles($stored);

            throw $exception;
        }
    }
}
