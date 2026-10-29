<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\TicketImport;

final class TicketImportFinishedNotification extends BaseNotification
{
    public function __construct(public readonly TicketImport $import)
    {
        parent::__construct();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    protected function title(object $notifiable): string
    {
        return sprintf('Import of %s finished', $this->import->original_name);
    }

    protected function body(object $notifiable): string
    {
        return sprintf('%d tickets imported, %d rows failed.', $this->import->succeeded_rows, $this->import->failed_rows);
    }

    protected function url(): string
    {
        return route('admin.imports.show', $this->import);
    }

    protected function actionText(): string
    {
        return 'View import';
    }
}
