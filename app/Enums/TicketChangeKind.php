<?php

declare(strict_types=1);

namespace App\Enums;

enum TicketChangeKind: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Replied = 'replied';
    case InternalNote = 'internal_note';
    case StatusChanged = 'status_changed';
    case PriorityChanged = 'priority_changed';
    case Assigned = 'assigned';
    case Unassigned = 'unassigned';
    case SlaApproaching = 'sla_approaching';
    case SlaBreached = 'sla_breached';
    case ImportFinished = 'import_finished';
    case Notification = 'notification';
}
