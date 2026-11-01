<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Tickets\RecalculateTicketSla;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RecalculateActiveTicketSlas implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct()
    {
        $this->onQueue('default');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('sla-recalculation'))->releaseAfter(30)->expireAfter(3600)];
    }

    public function handle(Container $container, Config $config): void
    {
        $container->forgetScopedInstances();
        $recalculate = $container->make(RecalculateTicketSla::class);
        $now = CarbonImmutable::now();

        Ticket::query()
            ->whereIn('status', array_map(static fn (TicketStatus $status): string => $status->value, TicketStatus::active()))
            ->select('id')
            ->chunkById(max(1, (int) $config->get('sla.scan_chunk_size')), static function (Collection $tickets) use ($recalculate, $now): void {
                foreach ($tickets as $ticket) {
                    $recalculate->handle((int) $ticket->getKey(), $now);
                }
            });
    }

    public function failed(Throwable $e): void
    {
        Log::error('Active ticket SLA recalculation failed.', ['exception' => $e->getMessage()]);
    }
}
