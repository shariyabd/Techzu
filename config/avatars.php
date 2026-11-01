<?php

declare(strict_types=1);

return [
    'disk' => env('AVATARS_DISK', 'local'),

    'directory' => 'avatars',

    'max_kb' => (int) env('AVATARS_MAX_KB', 2048),

    'extensions' => ['jpg', 'jpeg', 'png', 'webp'],

    'mime_types' => ['image/jpeg', 'image/png', 'image/webp'],

    'min_dimension' => 64,

    'max_dimension' => 4096,

    'size' => 256,

    'cache_seconds' => 604800,
];
