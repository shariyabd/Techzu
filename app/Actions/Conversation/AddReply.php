<?php

declare(strict_types=1);

namespace App\Actions\Conversation;

use App\Actions\Tickets\ChangeTicketStatus;
use App\Enums\TicketStatus;
use App\Events\TicketReplied;
use App\Exceptions\Domain\InternalNoteForbidden;
use App\Exceptions\Domain\TicketClosed;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Services\Attachments\AttachmentStorer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AddReply
{
    private const array STATUSES_RESUMED_BY_CUSTOMER_REPLY = [TicketStatus::WaitingForCustomer, TicketStatus::Resolved];

    public function __construct(
        private readonly AttachmentStorer $attachments,
        private readonly ChangeTicketStatus $changeStatus,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     *
     * @throws InternalNoteForbidden|TicketClosed
     */
    public function handle(User $author, Ticket $ticket, string $body, bool $isInternal, array $files = []): TicketReply
    {
        if ($isInternal && $author->cannot('addInternalNote', $ticket)) {
            throw new InternalNoteForbidden;
        }

        $staged = $this->attachments->stage($files);
        $committed = false;

        try {
            [$current, $reply] = DB::transaction(function () use ($author, $ticket, $body, $isInternal, $staged, &$committed): array {
                DB::afterCommit(static function () use (&$committed): void {
                    $committed = true;
                });

                $current = Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();

                if ($current->status === TicketStatus::Closed) {
                    throw new TicketClosed(sprintf('Ticket %s is closed and no longer accepts replies.', $current->number));
                }

                $reply = new TicketReply(['body' => $body, 'is_internal' => $isInternal]);
                $reply->ticket()->associate($current);
                $reply->author()->associate($author);
                $reply->save();

                $this->attachments->persist($current, $reply, $author, $staged, $isInternal);

                if (! $isInternal
                    && $author->id === $current->customer_id
                    && in_array($current->status, self::STATUSES_RESUMED_BY_CUSTOMER_REPLY, true)) {
                    $current = $this->changeStatus->handleLocked($author, $current, TicketStatus::InProgress);
                }

                if (! $isInternal) {
                    $current->touch();
                }

                return [$current, $reply];
            });
        } catch (Throwable $exception) {
            if (! $committed) {
                $this->attachments->discard($staged);
            }

            throw $exception;
        }

        TicketReplied::dispatch($current, $reply, $author);

        return $reply;
    }
}
