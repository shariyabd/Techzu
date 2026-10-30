<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

Schedule::command('sla:scan')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('activity:prune')->dailyAt('02:00')->onOneServer();
Schedule::command('notifications:prune')->dailyAt('02:15')->onOneServer();
Schedule::command('queue:prune-batches', ['--hours' => config('retention.job_batches_hours')])->dailyAt('02:30')->onOneServer();
Schedule::command('queue:prune-failed', ['--hours' => config('retention.failed_jobs_hours')])->dailyAt('02:45')->onOneServer();
