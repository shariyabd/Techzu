<?php

declare(strict_types=1);

return [
    'number_prefix' => env('TICKET_NUMBER_PREFIX', 'TKT'),

    'per_page' => [
        'default' => (int) env('TICKETS_PER_PAGE', 20),
        'max' => (int) env('TICKETS_PER_PAGE_MAX', 100),
    ],

    'dashboard' => [
        'resolved_window_days' => (int) env('DASHBOARD_RESOLVED_WINDOW_DAYS', 30),
        'cache_ttl_seconds' => (int) env('DASHBOARD_CACHE_TTL_SECONDS', 60),
    ],

    'show' => [
        'max_pages' => (int) env('TICKETS_SHOW_MAX_PAGES', 50),
    ],

    'auto_assign' => (bool) env('TICKETS_AUTO_ASSIGN', true),
];
