<?php

declare(strict_types=1);

use App\Enums\TicketPriority;

it('ranks priorities by urgency from low to critical', function (): void {
    expect(array_map(fn (TicketPriority $priority): int => $priority->rank(), [
        TicketPriority::Low,
        TicketPriority::Medium,
        TicketPriority::High,
        TicketPriority::Critical,
    ]))->toBe([1, 2, 3, 4]);
});
