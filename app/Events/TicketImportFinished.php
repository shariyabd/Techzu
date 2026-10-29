<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\TicketImport;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class TicketImportFinished implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly TicketImport $import,
    ) {}
}
