<?php

declare(strict_types=1);

namespace App\Enums;

enum ActivityType: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Assigned = 'assigned';
    case Claimed = 'claimed';
    case Unassigned = 'unassigned';
    case StatusChanged = 'status_changed';
    case PriorityChanged = 'priority_changed';
    case CategoryChanged = 'category_changed';
    case Replied = 'replied';
    case InternalNote = 'internal_note';
    case SlaPaused = 'sla_paused';
    case SlaResumed = 'sla_resumed';
    case SlaApproaching = 'sla_approaching';
    case Escalated = 'escalated';
    case Imported = 'imported';

    /** @param  array<string, mixed>  $properties */
    public function describe(array $properties): string
    {
        $from = $properties['from'] ?? null;
        $to = $properties['to'] ?? null;

        return match ($this) {
            self::Created => 'Ticket created',
            self::Updated => self::describeUpdate($properties['fields'] ?? null),
            self::Assigned => $from === null
                ? sprintf('Assigned to %s', self::nameOf($to))
                : sprintf('Reassigned from %s to %s', self::nameOf($from), self::nameOf($to)),
            self::Claimed => sprintf('Claimed by %s', self::nameOf($to)),
            self::Unassigned => sprintf('Unassigned from %s', self::nameOf($from)),
            self::StatusChanged => sprintf('Status changed from %s to %s', self::statusLabel($from), self::statusLabel($to)),
            self::PriorityChanged => sprintf('Priority changed from %s to %s', self::priorityLabel($from), self::priorityLabel($to)),
            self::CategoryChanged => sprintf('Category changed from %s to %s', self::nameOf($from), self::nameOf($to)),
            self::Replied => ($properties['author_is_staff'] ?? false) === true ? 'Agent replied' : 'Customer replied',
            self::InternalNote => 'Internal note added',
            self::SlaPaused => 'SLA paused while waiting for the customer',
            self::SlaResumed => 'SLA resumed',
            self::SlaApproaching => 'SLA breach approaching',
            self::Escalated => 'Ticket escalated: SLA breached',
            self::Imported => is_int($properties['row'] ?? null)
                ? sprintf('Ticket imported from CSV (row %d)', $properties['row'])
                : 'Ticket imported from CSV',
        };
    }

    public function isStaffOnly(): bool
    {
        return $this === self::InternalNote;
    }

    private static function describeUpdate(mixed $fields): string
    {
        if (! is_array($fields)) {
            return 'Ticket updated';
        }

        $names = array_values(array_filter($fields, 'is_string'));

        return $names === [] ? 'Ticket updated' : sprintf('Ticket updated (%s)', implode(', ', $names));
    }

    private static function nameOf(mixed $value): string
    {
        if (is_array($value) && isset($value['name']) && is_string($value['name'])) {
            return $value['name'];
        }

        return is_string($value) && $value !== '' ? $value : 'nobody';
    }

    private static function statusLabel(mixed $value): string
    {
        return is_string($value) ? (TicketStatus::tryFrom($value)?->label() ?? $value) : 'unknown';
    }

    private static function priorityLabel(mixed $value): string
    {
        return is_string($value) ? (TicketPriority::tryFrom($value)?->label() ?? $value) : 'unknown';
    }
}
