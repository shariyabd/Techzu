<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PruneNotificationsCommand extends Command
{
    /** @var string */
    protected $signature = 'notifications:prune';

    /** @var string */
    protected $description = 'Delete read notifications older than the configured retention period.';

    public function handle(): int
    {
        $cutoff = CarbonImmutable::now()->subDays(max(1, (int) config('retention.notifications_days')));
        $chunkSize = max(1, (int) config('retention.prune_chunk_size'));
        $deleted = 0;

        do {
            $ids = DB::table('notifications')
                ->whereNotNull('read_at')
                ->where('created_at', '<', $cutoff)
                ->limit($chunkSize)
                ->pluck('id')
                ->all();

            if ($ids !== []) {
                $deleted += DB::table('notifications')->whereIn('id', $ids)->delete();
            }
        } while (count($ids) === $chunkSize);

        $this->components->info(sprintf('Pruned %d read notifications.', $deleted));

        return self::SUCCESS;
    }
}
