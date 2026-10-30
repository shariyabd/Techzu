<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function insertNotification(int $userId, ?CarbonImmutable $readAt, CarbonImmutable $createdAt): string
{
    $id = (string) Str::uuid();

    DB::table('notifications')->insert([
        'id' => $id,
        'type' => 'test',
        'notifiable_type' => 'App\\Models\\User',
        'notifiable_id' => $userId,
        'data' => '{}',
        'read_at' => $readAt,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    return $id;
}

it('deletes old read notifications and keeps unread and recent ones', function (): void {
    config(['retention.notifications_days' => 30, 'retention.prune_chunk_size' => 2]);
    $now = CarbonImmutable::now();
    $old = $now->subDays(31);

    $oldRead = [
        insertNotification(1, $old, $old),
        insertNotification(1, $old, $old),
        insertNotification(1, $old, $old),
    ];
    $oldUnread = insertNotification(1, null, $old);
    $recentRead = insertNotification(1, $now, $now->subDays(5));

    $this->artisan('notifications:prune')->assertSuccessful();

    expect(DB::table('notifications')->whereIn('id', $oldRead)->count())->toBe(0)
        ->and(DB::table('notifications')->whereIn('id', [$oldUnread, $recentRead])->count())->toBe(2);
});

it('deletes old activity entries in chunks and keeps recent ones', function (): void {
    config(['activitylog.delete_records_older_than_days' => 30, 'retention.prune_chunk_size' => 2]);
    $now = CarbonImmutable::now();
    $row = static fn (CarbonImmutable $at): array => ['log_name' => 'ticket', 'description' => 'x', 'created_at' => $at, 'updated_at' => $at];

    DB::table('activity_log')->insert([
        $row($now->subDays(31)),
        $row($now->subDays(40)),
        $row($now->subDays(50)),
        $row($now->subDays(5)),
    ]);

    $this->artisan('activity:prune')->assertSuccessful();

    expect(DB::table('activity_log')->count())->toBe(1);
});

it('registers the daily cleanup of growing tables', function (string $command): void {
    $this->app->make(ConsoleKernel::class)->bootstrap();

    $events = collect($this->app->make(Schedule::class)->events())
        ->filter(static fn (ScheduledEvent $event): bool => str_contains((string) $event->command, $command));

    expect($events)->toHaveCount(1);
})->with(['sla:scan', 'activity:prune', 'notifications:prune', 'queue:prune-batches', 'queue:prune-failed']);
