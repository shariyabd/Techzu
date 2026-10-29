<?php

declare(strict_types=1);

namespace App\Enums;

enum Permission: string
{
    case TicketsCreate = 'tickets.create';
    case TicketsCreateOnBehalf = 'tickets.create.on_behalf';
    case TicketsViewAny = 'tickets.view.any';
    case TicketsViewOwn = 'tickets.view.own';
    case TicketsReply = 'tickets.reply';
    case TicketsNotesInternal = 'tickets.notes.internal';
    case TicketsClaim = 'tickets.claim';
    case TicketsUpdateAny = 'tickets.update.any';
    case TicketsUpdateAssigned = 'tickets.update.assigned';
    case TicketsUpdateOwn = 'tickets.update.own';
    case TicketsAssignAny = 'tickets.assign.any';
    case TicketsHandoffOwn = 'tickets.handoff.own';
    case DashboardView = 'dashboard.view';
    case ImportsManage = 'imports.manage';
    case UsersManage = 'users.manage';
    case CategoriesManage = 'categories.manage';
    case HolidaysManage = 'holidays.manage';

    /** @return list<self> */
    public static function forRole(Role $role): array
    {
        return match ($role) {
            Role::Customer => [
                self::TicketsCreate,
                self::TicketsViewOwn,
                self::TicketsReply,
                self::TicketsUpdateOwn,
            ],
            Role::Agent => [
                self::TicketsCreate,
                self::TicketsCreateOnBehalf,
                self::TicketsViewAny,
                self::TicketsReply,
                self::TicketsNotesInternal,
                self::TicketsClaim,
                self::TicketsUpdateAssigned,
                self::TicketsHandoffOwn,
                self::DashboardView,
            ],
            Role::Admin => [
                self::TicketsCreate,
                self::TicketsCreateOnBehalf,
                self::TicketsViewAny,
                self::TicketsReply,
                self::TicketsNotesInternal,
                self::TicketsClaim,
                self::TicketsUpdateAny,
                self::TicketsAssignAny,
                self::DashboardView,
                self::ImportsManage,
                self::UsersManage,
                self::CategoriesManage,
                self::HolidaysManage,
            ],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::TicketsCreate => 'Create tickets',
            self::TicketsCreateOnBehalf => 'Create tickets on behalf of customers',
            self::TicketsViewAny => 'View all tickets',
            self::TicketsViewOwn => 'View own tickets',
            self::TicketsReply => 'Reply to tickets',
            self::TicketsNotesInternal => 'Read and write internal notes',
            self::TicketsClaim => 'Claim unassigned tickets',
            self::TicketsUpdateAny => 'Update any ticket',
            self::TicketsUpdateAssigned => 'Update tickets assigned to them',
            self::TicketsUpdateOwn => 'Update their own tickets',
            self::TicketsAssignAny => 'Assign tickets to any agent',
            self::TicketsHandoffOwn => 'Hand off their own tickets',
            self::DashboardView => 'View the dashboard',
            self::ImportsManage => 'Import tickets from CSV',
            self::UsersManage => 'Manage users and roles',
            self::CategoriesManage => 'Manage categories',
            self::HolidaysManage => 'Manage holidays',
        };
    }
}
