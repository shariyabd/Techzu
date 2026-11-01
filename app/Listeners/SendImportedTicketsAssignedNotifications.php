<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ImportedTicketsAutoAssigned;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\ImportedTicketsAssignedNotification;
use Illuminate\Support\Facades\Notification;

final class SendImportedTicketsAssignedNotifications
{
    public function handle(ImportedTicketsAutoAssigned $event): void
    {
        $countsByAgent = Ticket::query()
            ->where('ticket_import_id', $event->import->id)
            ->whereNotNull('assigned_agent_id')
            ->groupBy('assigned_agent_id')
            ->selectRaw('assigned_agent_id, count(*) as assigned_count')
            ->pluck('assigned_count', 'assigned_agent_id');

        if ($countsByAgent->isEmpty()) {
            return;
        }

        User::query()
            ->whereKey($countsByAgent->keys()->all())
            ->orderBy('id')
            ->get(['id', 'name', 'email'])
            ->each(static function (User $agent) use ($event, $countsByAgent): void {
                Notification::send($agent, new ImportedTicketsAssignedNotification($event->import, (int) $countsByAgent[$agent->id]));
            });
    }
}
