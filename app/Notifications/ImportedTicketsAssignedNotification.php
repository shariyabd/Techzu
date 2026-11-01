<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\TicketImport;

final class ImportedTicketsAssignedNotification extends BaseNotification
{
    public function __construct(
        public readonly TicketImport $import,
        public readonly int $count,
    ) {
        parent::__construct();
    }

    protected function title(object $notifiable): string
    {
        return $this->count === 1
            ? '1 imported ticket was assigned to you'
            : sprintf('%d imported tickets were assigned to you', $this->count);
    }

    protected function body(object $notifiable): string
    {
        return sprintf('They come from the import of %s. Imported tickets keep their original SLA, so some may already be close to or past their due time.', $this->import->original_name);
    }

    protected function url(): string
    {
        return route('tickets.index');
    }

    protected function actionText(): string
    {
        return 'View your tickets';
    }
}
