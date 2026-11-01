<?php

declare(strict_types=1);

use App\Enums\Role;

return [
    'login_accounts' => [
        'enabled' => (bool) env('DEMO_LOGIN_ACCOUNTS', true),
        'password' => env('DEMO_LOGIN_PASSWORD', 'password'),
        'emails' => [
            Role::Admin->value => env('DEMO_LOGIN_ADMIN_EMAIL', 'admin@example.com'),
            Role::Agent->value => env('DEMO_LOGIN_AGENT_EMAIL', 'agent1@example.com'),
            Role::Customer->value => env('DEMO_LOGIN_CUSTOMER_EMAIL', 'customer1@example.com'),
        ],
    ],
];
