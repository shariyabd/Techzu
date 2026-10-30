<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\EscalationLevel;
use App\Events\TicketSlaApproaching;
use App\Events\TicketSlaBreached;
use App\Models\Ticket;
use App\Services\Sla\SlaQuery;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

final class ScanSlaCommand extends Command
{
    /** @var string */
    protected $signature = 'sla:scan';

    /** @var string */
    protected $description = 'Escalate active tickets whose SLA is approaching or breached.';

    public function handle(): int
    {
        $runAt = CarbonImmutable::now()->utc()->startOfSecond();

        $approaching = $this->escalate(EscalationLevel::Approaching, $runAt);
        $breached = $this->escalate(EscalationLevel::Breached, $runAt);

        $this->components->info(sprintf(
            'SLA scan at %s: %d approaching, %d breached.',
            $runAt->toIso8601String(),
            $approaching,
            $breached,
        ));

        return self::SUCCESS;
    }

    private function escalate(EscalationLevel $level, CarbonImmutable $runAt): int
    {
        $escalated = 0;

        $this->candidates($level, $runAt)
            ->select('id')
            ->chunkById(max(1, (int) config('sla.scan_chunk_size')), function (Collection $chunk) use ($level, $runAt, &$escalated): void {
                $ids = $this->claim($level, $runAt, $chunk->modelKeys());
                $this->dispatchFor($level, $ids);
                $escalated += count($ids);
            });

        return $escalated;
    }

    /** @return Builder<Ticket> */
    private function candidates(EscalationLevel $level, CarbonImmutable $runAt): Builder
    {
        $query = Ticket::query()->where('escalation_level', '<', $level->value);

        return match ($level) {
            EscalationLevel::Approaching => SlaQuery::whereApproaching($query, $runAt),
            EscalationLevel::Breached => SlaQuery::whereBreached($query, $runAt),
            EscalationLevel::None => throw new LogicException('Tickets are never escalated to level None.'),
        };
    }

    /**
     * @param  array<int, mixed>  $candidateIds
     * @return list<int>
     */
    private function claim(EscalationLevel $level, CarbonImmutable $runAt, array $candidateIds): array
    {
        return DB::transaction(function () use ($level, $runAt, $candidateIds): array {
            /** @var list<int> $ids */
            $ids = $this->candidates($level, $runAt)
                ->whereKey($candidateIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->values()
                ->all();

            if ($ids === []) {
                return [];
            }

            $values = ['escalation_level' => $level->value];
            if ($level === EscalationLevel::Breached) {
                $values['escalated_at'] = $runAt;
            }

            Ticket::query()
                ->whereKey($ids)
                ->where('escalation_level', '<', $level->value)
                ->toBase()
                ->update($values);

            return $ids;
        }, 3);
    }

    /** @param list<int> $ids */
    private function dispatchFor(EscalationLevel $level, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        Ticket::query()
            ->whereKey($ids)
            ->orderBy('id')
            ->get()
            ->each(static function (Ticket $ticket) use ($level): void {
                $level === EscalationLevel::Breached
                    ? TicketSlaBreached::dispatch($ticket)
                    : TicketSlaApproaching::dispatch($ticket);
            });
    }
}
