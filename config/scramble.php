<?php

declare(strict_types=1);

use App\Http\Middleware\AuthorizeApiDocs;
use Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy;

return [
    'api_path' => 'api/v1',
    'export_path' => 'docs/api/openapi.json',
    'info' => [
        'version' => env('API_VERSION', '1.0.0'),
        'description' => 'Support Ticket & SLA Management API. Get a Sanctum bearer token from `POST /api/v1/auth/tokens` with `email`, `password` and `device_name`, then send it as `Authorization: Bearer <token>`. Errors are `{"message", "code"}`; branch on `code` or the HTTP status.',
    ],
    'ui' => [
        'title' => env('APP_NAME', 'Laravel').' API',
    ],
    'middleware' => [
        'web',
        AuthorizeApiDocs::class,
    ],
    'security_strategy' => MiddlewareAuthSecurityStrategy::class,
];
