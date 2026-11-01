<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\EscalationLevel;
use App\Enums\TicketStatus;
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

        $redelivered = $this->redeliver($runAt);
        $approaching = $this->escalate(EscalationLevel::Approaching, $runAt);
        $breached = $this->escalate(EscalationLevel::Breached, $runAt);

        $this->components->info(sprintf(
            'SLA scan at %s: %d approaching, %d breached, %d redelivered.',
            $runAt->toIso8601String(),
            $approaching,
            $breached,
            $redelivered,
        ));

        return self::SUCCESS;
    }

    private function redeliver(CarbonImmutable $runAt): int
    {
        $redelivered = 0;
        $cutoff = $runAt->subSeconds(max(0, (int) config('sla.redelivery_grace_seconds', 120)));

        Ticket::query()
            ->whereIn('status', array_map(static fn (TicketStatus $status): string => $status->value, TicketStatus::active()))
            ->whereNotNull('escalated_at')
            ->where('escalated_at', '<=', $cutoff)
            ->whereColumn('escalation_notified_level', '<', 'escalation_level')
            ->chunkById(max(1, (int) config('sla.scan_chunk_size')), static function (Collection $tickets) use (&$redelivered): void {
                foreach ($tickets as $ticket) {
                    self::announce($ticket, $ticket->escalation_level);
                    $redelivered++;
                }
            });

        return $redelivered;
    }

    private function escalate(EscalationLevel $level, CarbonImmutable $runAt): int
    {
        $escalated = 0;

        $this->candidates($level, $runAt)
            ->select('id')
            ->chunkById(max(1, (int) config('sla.scan_chunk_size')), function (Collection $chunk) use ($level, $runAt, &$escalated): void {
                $escalated += $this->escalateChunk($level, $runAt, $chunk->modelKeys());
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

    /** @param array<int, mixed> $candidateIds */
    private function escalateChunk(EscalationLevel $level, CarbonImmutable $runAt, array $candidateIds): int
    {
        return DB::transaction(function () use ($level, $runAt, $candidateIds): int {
            $tickets = $this->candidates($level, $runAt)
                ->whereKey($candidateIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($tickets->isEmpty()) {
                return 0;
            }

            $notifiedCeiling = $level->value - 1;

            Ticket::query()
                ->whereKey($tickets->modelKeys())
                ->where('escalation_level', '<', $level->value)
                ->toBase()
                ->update([
                    'escalation_level' => $level->value,
                    'escalated_at' => $runAt,
                    'escalation_notified_level' => DB::raw('LEAST(escalation_notified_level, '.$notifiedCeiling.')'),
                ]);

            $tickets->each(static function (Ticket $ticket) use ($level, $runAt, $notifiedCeiling): void {
                $notified = min($ticket->escalation_notified_level->value, $notifiedCeiling);
                $ticket->forceFill([
                    'escalation_level' => $level,
                    'escalated_at' => $runAt,
                    'escalation_notified_level' => EscalationLevel::from($notified),
                ])->syncOriginal();

                self::announce($ticket, $level);
            });

            return $tickets->count();
        }, 3);
    }

    private static function announce(Ticket $ticket, EscalationLevel $level): void
    {
        match ($level) {
            EscalationLevel::Breached => TicketSlaBreached::dispatch($ticket),
            EscalationLevel::Approaching => TicketSlaApproaching::dispatch($ticket),
            EscalationLevel::None => null,
        };
    }
}
