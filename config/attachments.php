<?php

declare(strict_types=1);

return [
    'disk' => env('ATTACHMENTS_DISK', 'local'),

    'directory' => 'attachments',

    'max_kb' => (int) env('ATTACHMENTS_MAX_KB', 10240),

    'max_files' => (int) env('ATTACHMENTS_MAX_FILES', 5),

    'extensions' => ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'txt', 'csv', 'doc', 'docx', 'xls', 'xlsx', 'zip'],
];
