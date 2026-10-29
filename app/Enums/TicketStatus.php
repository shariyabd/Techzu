<?php

declare(strict_types=1);

namespace App\Enums;

enum TicketStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case WaitingForCustomer = 'waiting_for_customer';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::InProgress => 'In Progress',
            self::WaitingForCustomer => 'Waiting for Customer',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::InProgress, self::WaitingForCustomer, self::Resolved],
            self::InProgress => [self::WaitingForCustomer, self::Resolved],
            self::WaitingForCustomer => [self::InProgress, self::Resolved],
            self::Resolved => [self::Closed, self::InProgress],
            self::Closed => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function isActive(): bool
    {
        return in_array($this, self::active(), true);
    }

    /** @return list<self> */
    public static function active(): array
    {
        return [self::Open, self::InProgress, self::WaitingForCustomer];
    }

    /** @return list<self> */
    public static function finished(): array
    {
        return [self::Resolved, self::Closed];
    }
}
