<?php

declare(strict_types=1);

namespace App\Services\Tickets;

use App\Enums\ActivityType;
use App\Enums\Permission;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final readonly class TicketVisibility
{
    private function __construct(
        public int $viewerId,
        public bool $seesAllTickets,
        public bool $seesOwnTickets,
        public bool $seesInternal,
    ) {}

    public static function of(User $viewer): self
    {
        return new self(
            $viewer->id,
            $viewer->can(Permission::TicketsViewAny->value),
            $viewer->can(Permission::TicketsViewOwn->value),
            $viewer->can(Permission::TicketsNotesInternal->value),
        );
    }

    public function seesAnyTicket(): bool
    {
        return $this->seesAllTickets || $this->seesOwnTickets;
    }

    public function canSee(Ticket $ticket): bool
    {
        return $this->seesAllTickets
            || ($this->seesOwnTickets && $ticket->customer_id === $this->viewerId);
    }

    public function scopeKey(): ?string
    {
        return match (true) {
            $this->seesAllTickets => 'all',
            $this->seesOwnTickets => 'customer:'.$this->viewerId,
            default => null,
        };
    }

    /** @param Builder<Ticket> $query */
    public function restrictTickets(Builder $query): void
    {
        if ($this->seesAllTickets) {
            return;
        }

        if ($this->seesOwnTickets) {
            $query->where($query->qualifyColumn('customer_id'), $this->viewerId);

            return;
        }

        $query->whereRaw('0 = 1');
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    public function restrictInternal(Builder $query): void
    {
        if (! $this->seesInternal) {
            $query->where($query->qualifyColumn('is_internal'), false);
        }
    }

    /** @return list<string> */
    public function hiddenActivityEvents(): array
    {
        if ($this->seesInternal) {
            return [];
        }

        return array_values(array_map(
            static fn (ActivityType $type): string => $type->value,
            array_filter(ActivityType::cases(), static fn (ActivityType $type): bool => $type->isStaffOnly()),
        ));
    }
}
