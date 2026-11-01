<?php

declare(strict_types=1);

namespace App\Services\Tickets;

use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;

final class ResponseQuery
{
    public const string UNRESPONDED = 'unresponded';

    public const string AWAITING = 'awaiting';

    public const array FILTERS = [
        self::UNRESPONDED => 'Unresponded',
        self::AWAITING => 'Awaiting reply',
    ];

    public static function unrespondedSql(): string
    {
        return 'NOT EXISTS (SELECT 1 FROM ticket_replies AS responses WHERE responses.ticket_id = tickets.id AND responses.is_internal = 0 AND responses.author_id <> tickets.customer_id)';
    }

    public static function awaitingSql(): string
    {
        return '(SELECT latest.author_id FROM ticket_replies AS latest WHERE latest.ticket_id = tickets.id AND latest.is_internal = 0 ORDER BY latest.created_at DESC, latest.id DESC LIMIT 1) = tickets.customer_id';
    }

    /**
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public static function where(Builder $query, string $filter): Builder
    {
        return match ($filter) {
            self::UNRESPONDED => $query->whereRaw(self::unrespondedSql()),
            self::AWAITING => $query->whereRaw(self::awaitingSql()),
            default => $query,
        };
    }
}
