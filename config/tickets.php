<?php

declare(strict_types=1);

return [
    'number_prefix' => env('TICKET_NUMBER_PREFIX', 'TKT'),

    'per_page' => [
        'default' => (int) env('TICKETS_PER_PAGE', 20),
        'max' => 100,
    ],
];
