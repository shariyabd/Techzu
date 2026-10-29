<?php

declare(strict_types=1);

return [
    'disk' => env('IMPORTS_DISK', 'local'),
    'max_upload_kb' => (int) env('IMPORTS_MAX_UPLOAD_KB', 51200),
    'chunk_size' => (int) env('IMPORTS_CHUNK_SIZE', 500),
];
