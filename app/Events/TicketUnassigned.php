<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class TicketUnassigned implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly ?User $actor,
        public readonly User $previousAgent,
    ) {}
}
