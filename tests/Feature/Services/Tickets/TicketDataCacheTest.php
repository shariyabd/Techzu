<?php

declare(strict_types=1);

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Dashboard\DashboardStats;
use App\Services\Tickets\TicketDataCache;
use App\Services\Tickets\TicketDataVersion;
use App\Services\Tickets\TicketQueueCounts;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    config(['cache.default' => 'database']);
    app()->forgetInstance('cache.store');
    app()->forgetInstance(Repository::class);
    app()->forgetInstance(TicketDataVersion::class);
    app()->forgetInstance(TicketDataCache::class);
    Cache::clearResolvedInstances();
    DB::table('cache')->delete();
});

it('keeps one cache row per viewer scope however often the ticket data version rotates', function (): void {
    $agent = User::factory()->agent()->create();
    $customer = User::factory()->customer()->create();
    $version = app(TicketDataVersion::class);

    for ($rotation = 0; $rotation < 5; $rotation++) {
        Ticket::factory()->status(TicketStatus::Open)->create(['customer_id' => $customer->id]);
        $version->rotate();
        app(TicketQueueCounts::class)->for($agent);
        app(TicketQueueCounts::class)->for($customer);
        app(DashboardStats::class)->summary();
    }

    $keys = DB::table('cache')->pluck('key')->map(fn (string $key): string => str_replace((string) config('cache.prefix'), '', $key));

    expect($keys->filter(fn (string $key): bool => str_starts_with($key, 'tickets:queue-counts:'))->values()->all())
        ->toEqualCanonicalizing(['tickets:queue-counts:all', 'tickets:queue-counts:customer:'.$customer->id])
        ->and($keys->filter(fn (string $key): bool => $key === DashboardStats::CACHE_KEY))->toHaveCount(1)
        ->and(app(TicketQueueCounts::class)->for($customer)['active']['total'])->toBe(5)
        ->and(app(TicketQueueCounts::class)->for($agent)['active']['total'])->toBe(5)
        ->and(app(DashboardStats::class)->summary()['totals']['open'])->toBe(5);
});

it('recomputes when a slow reader overwrites the key with figures from an older version', function (): void {
    $cache = app(TicketDataCache::class);
    $version = app(TicketDataVersion::class);
    $staleStamp = $version->current().'|';
    $version->rotate();

    Cache::put('tickets:queue-counts:all', ['version' => $staleStamp, 'data' => ['stale']], 60);

    expect($cache->remember('tickets:queue-counts:all', 60, fn (): array => ['fresh']))->toBe(['fresh'])
        ->and($cache->remember('tickets:queue-counts:all', 60, fn (): array => ['recomputed']))->toBe(['fresh']);
});
