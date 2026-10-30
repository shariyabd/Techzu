<?php

declare(strict_types=1);

return [
    'notifications_days' => (int) env('NOTIFICATIONS_RETENTION_DAYS', 90),
    'failed_jobs_hours' => (int) env('FAILED_JOBS_RETENTION_HOURS', 168),
    'job_batches_hours' => (int) env('JOB_BATCHES_RETENTION_HOURS', 168),
    'prune_chunk_size' => (int) env('PRUNE_CHUNK_SIZE', 1000),
];
