<?php

declare(strict_types=1);

namespace App\Services\Sla;

use App\Enums\SlaState;
use App\Enums\TicketStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class SlaQuery
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $q
     * @return Builder<TModel>
     */
    public static function whereState(Builder $q, SlaState $state, CarbonImmutable $now): Builder
    {
        return match ($state) {
            SlaState::Stopped => $q->whereIn($q->qualifyColumn('status'), self::values(TicketStatus::finished())),
            SlaState::Paused => self::active($q)->whereNotNull($q->qualifyColumn('sla_paused_at')),
            SlaState::Breached => self::whereBreached($q, $now),
            SlaState::Approaching => self::whereApproaching($q, $now),
            SlaState::Within => self::running($q)->where($q->qualifyColumn('sla_warning_at'), '>', $now),
        };
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $q
     * @return Builder<TModel>
     */
    public static function whereBreached(Builder $q, CarbonImmutable $now): Builder
    {
        return self::running($q)->where($q->qualifyColumn('sla_due_at'), '<=', $now);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $q
     * @return Builder<TModel>
     */
    public static function whereApproaching(Builder $q, CarbonImmutable $now): Builder
    {
        return self::running($q)
            ->where($q->qualifyColumn('sla_warning_at'), '<=', $now)
            ->where($q->qualifyColumn('sla_due_at'), '>', $now);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $q
     * @return Builder<TModel>
     */
    private static function running(Builder $q): Builder
    {
        return self::active($q)->whereNull($q->qualifyColumn('sla_paused_at'));
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $q
     * @return Builder<TModel>
     */
    private static function active(Builder $q): Builder
    {
        return $q->whereIn($q->qualifyColumn('status'), self::values(TicketStatus::active()));
    }

    /**
     * @param  list<TicketStatus>  $statuses
     * @return list<string>
     */
    private static function values(array $statuses): array
    {
        return array_map(static fn (TicketStatus $s): string => $s->value, $statuses);
    }
}
