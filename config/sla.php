<?php

declare(strict_types=1);

use App\Enums\TicketPriority;

return [
    'resolution_minutes' => [
        TicketPriority::Low->value => (int) env('SLA_MINUTES_LOW', 2880),
        TicketPriority::Medium->value => (int) env('SLA_MINUTES_MEDIUM', 1440),
        TicketPriority::High->value => (int) env('SLA_MINUTES_HIGH', 480),
        TicketPriority::Critical->value => (int) env('SLA_MINUTES_CRITICAL', 120),
    ],

    'scan_chunk_size' => (int) env('SLA_SCAN_CHUNK_SIZE', 500),

    'warning_threshold_percent' => (int) env('SLA_WARNING_THRESHOLD_PERCENT', 75),
];
