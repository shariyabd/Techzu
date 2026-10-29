<?php

declare(strict_types=1);

namespace App\Actions\Tickets;

use App\Events\TicketUpdated;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class UpdateTicket
{
    private const FIELDS = ['subject', 'description', 'category_id'];

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, Ticket $ticket, array $data): Ticket
    {
        return DB::transaction(function () use ($actor, $ticket, $data): Ticket {
            $this->lockAndRefresh($ticket);

            Gate::forUser($actor)->authorize('update', $ticket);

            $changes = [];

            foreach (self::FIELDS as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }

                $old = $ticket->getAttribute($field);
                $new = $field === 'category_id' ? (int) $data[$field] : (string) $data[$field];
                $oldNormalized = $field === 'category_id' ? (int) $old : (string) $old;

                if ($oldNormalized === $new) {
                    continue;
                }

                $changes[$field] = ['from' => $oldNormalized, 'to' => $new];
            }

            if ($changes === []) {
                return $ticket;
            }

            $ticket->fill(array_map(static fn (array $change): mixed => $change['to'], $changes));
            $ticket->save();

            TicketUpdated::dispatch($ticket, $actor, $changes);

            return $ticket;
        });
    }

    private function lockAndRefresh(Ticket $ticket): void
    {
        $locked = Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
        $ticket->setRawAttributes($locked->getAttributes(), true);
    }
}
