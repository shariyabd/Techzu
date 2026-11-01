<?php

declare(strict_types=1);

namespace App\Services\Tickets;

use App\Enums\SlaState;
use App\Enums\TicketPriority;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Sla\SlaQuery;
use App\Services\Sla\SlaSettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class TicketFilter
{
    public const array SORTS = [
        '-created_at' => 'Newest first',
        'created_at' => 'Oldest first',
        '-updated_at' => 'Recently updated',
        'updated_at' => 'Least recently updated',
        'sla_due_at' => 'Due soonest',
        '-sla_due_at' => 'Due latest',
        '-priority' => 'Highest priority',
        'priority' => 'Lowest priority',
    ];

    public const string DEFAULT_SORT = '-created_at';

    private const array FULLTEXT_DRIVERS = ['mysql', 'mariadb'];

    public function __construct(
        private readonly SlaSettingsRepository $settings,
        private readonly Config $config,
    ) {}

    /** @param  array<string, mixed>  $input */
    public function isNumberLookup(array $input): bool
    {
        $number = $input['number'] ?? null;

        return (is_string($number) && trim($number) !== '') || $this->numberTypedAsKeyword($input['q'] ?? null) !== null;
    }

    /**
     * @param  Builder<Ticket>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Ticket>
     */
    public function apply(Builder $query, array $filters, User $viewer, CarbonImmutable $now): Builder
    {
        $query->visibleTo($viewer);
        $this->number($query, $filters['number'] ?? null);
        $this->keyword($query, $filters['q'] ?? null);
        $this->whereInList($query, 'tickets.status', $filters['status'] ?? null);
        $this->whereInList($query, 'tickets.priority', $filters['priority'] ?? null);
        $this->whereEquals($query, 'tickets.category_id', $filters['category_id'] ?? null);
        $this->whereEquals($query, 'tickets.assigned_agent_id', $filters['assigned_agent_id'] ?? null);
        $this->whereEquals($query, 'tickets.customer_id', $filters['customer_id'] ?? null);
        $this->createdBetween($query, $filters['created_from'] ?? null, $filters['created_to'] ?? null);

        if (filter_var($filters['unassigned'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->whereNull('tickets.assigned_agent_id');
        }

        $slaState = is_string($filters['sla_state'] ?? null) ? SlaState::tryFrom($filters['sla_state']) : null;

        if ($slaState !== null) {
            SlaQuery::whereState($query, $slaState, $now);
        }

        $resolvedSince = $filters['resolved_since'] ?? null;

        if ($resolvedSince instanceof CarbonImmutable) {
            $query->where('tickets.resolved_at', '>=', $resolvedSince);
        }

        $response = $filters['response'] ?? null;

        if (is_string($response)) {
            ResponseQuery::where($query, $response);
        }

        $sort = $filters['sort'] ?? null;
        $this->sort($query, is_string($sort) && array_key_exists($sort, self::SORTS) ? $sort : self::DEFAULT_SORT);

        return $query;
    }

    /** @param  Builder<Ticket>  $query */
    private function number(Builder $query, mixed $number): void
    {
        if (! is_string($number) || trim($number) === '') {
            return;
        }

        $value = Str::upper(trim($number));

        $this->whereNumberStartsWith($query, preg_match('/^\d/', $value) === 1 ? $this->numberPrefix().'-'.$value : $value);
    }

    private function numberTypedAsKeyword(mixed $input): ?string
    {
        if (! is_string($input)) {
            return null;
        }

        $value = Str::upper(trim($input));
        $prefix = $this->numberPrefix();

        if (preg_match('/^'.preg_quote($prefix, '/').'-\d[\d-]*$/', $value) === 1) {
            return $value;
        }

        if (preg_match('/^\d{4}-\d+$/', $value) === 1) {
            return $prefix.'-'.$value;
        }

        return null;
    }

    private function numberPrefix(): string
    {
        return Str::upper($this->config->string('tickets.number_prefix'));
    }

    /** @param  Builder<Ticket>  $query */
    private function whereNumberStartsWith(Builder $query, string $value): void
    {
        $query->where('tickets.number', 'like', $this->escapeLike($value).'%');
    }

    /** @param  Builder<Ticket>  $query */
    private function keyword(Builder $query, mixed $input): void
    {
        if (! is_string($input) || trim($input) === '') {
            return;
        }

        $number = $this->numberTypedAsKeyword($input);

        if ($number !== null) {
            $this->whereNumberStartsWith($query, $number);

            return;
        }

        if (in_array($query->getModel()->getConnection()->getDriverName(), self::FULLTEXT_DRIVERS, true)) {
            $expression = FulltextSearchTerm::toBooleanMode($input);

            if ($expression !== '') {
                $query->whereFullText(['tickets.subject', 'tickets.description'], $expression, ['mode' => 'boolean']);
            }

            return;
        }

        $needle = '%'.$this->escapeLike(trim($input)).'%';
        $query->where(static function (Builder $where) use ($needle): void {
            $where->where('tickets.subject', 'like', $needle)->orWhere('tickets.description', 'like', $needle);
        });
    }

    /** @param  Builder<Ticket>  $query */
    private function whereInList(Builder $query, string $column, mixed $values): void
    {
        if (! is_array($values)) {
            return;
        }

        $values = array_values(array_filter($values, static fn (mixed $value): bool => is_string($value) && $value !== ''));

        if ($values !== []) {
            $query->whereIn($column, $values);
        }
    }

    /** @param  Builder<Ticket>  $query */
    private function whereEquals(Builder $query, string $column, mixed $value): void
    {
        $id = match (true) {
            is_int($value) => $value,
            is_string($value) && ctype_digit($value) => (int) $value,
            default => null,
        };

        if ($id !== null) {
            $query->where($column, $id);
        }
    }

    /** @param  Builder<Ticket>  $query */
    private function createdBetween(Builder $query, mixed $from, mixed $to): void
    {
        $timezone = $this->settings->timezone();

        if (is_string($from) && $from !== '') {
            $query->where('tickets.created_at', '>=', CarbonImmutable::parse($from, $timezone)->startOfDay()->utc());
        }

        if (is_string($to) && $to !== '') {
            $query->where('tickets.created_at', '<=', CarbonImmutable::parse($to, $timezone)->endOfDay()->utc());
        }
    }

    /** @param  Builder<Ticket>  $query */
    private function sort(Builder $query, string $sort): void
    {
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        if ($column === 'priority') {
            $cases = [];
            $bindings = [];

            foreach (TicketPriority::cases() as $priority) {
                $cases[] = 'when ? then ?';
                $bindings[] = $priority->value;
                $bindings[] = $priority->rank();
            }

            $query->orderByRaw('case tickets.priority '.implode(' ', $cases).' end '.$direction, $bindings);
        } else {
            $query->orderBy('tickets.'.$column, $direction);
        }

        $query->orderBy('tickets.id', $direction);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
