<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\User;
use App\Notifications\InternalNoteAddedNotification;
use App\Services\Tickets\TicketVisibility;

final class StaffOnlyNotifications
{
    public const array INTERNAL_NOTE_TYPES = [
        InternalNoteAddedNotification::class,
    ];

    /** @return list<class-string> */
    public static function hiddenFrom(User $viewer): array
    {
        return TicketVisibility::of($viewer)->seesInternal ? [] : self::INTERNAL_NOTE_TYPES;
    }

    public static function isHiddenFrom(User $viewer, string $type): bool
    {
        return in_array($type, self::hiddenFrom($viewer), true);
    }
}
