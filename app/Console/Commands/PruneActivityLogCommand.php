<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PruneActivityLogCommand extends Command
{
    /** @var string */
    protected $signature = 'activity:prune';

    /** @var string */
    protected $description = 'Delete activity entries older than the configured retention period.';

    public function handle(): int
    {
        $cutoff = CarbonImmutable::now()->subDays(max(1, (int) config('activitylog.delete_records_older_than_days')));
        $chunkSize = max(1, (int) config('retention.prune_chunk_size'));
        $deleted = 0;

        do {
            $ids = DB::table('activity_log')
                ->where('created_at', '<', $cutoff)
                ->limit($chunkSize)
                ->pluck('id')
                ->all();

            if ($ids !== []) {
                $deleted += DB::table('activity_log')->whereIn('id', $ids)->delete();
            }
        } while (count($ids) === $chunkSize);

        $this->components->info(sprintf('Pruned %d activity entries.', $deleted));

        return self::SUCCESS;
    }
}
