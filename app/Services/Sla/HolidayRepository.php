<?php

declare(strict_types=1);

namespace App\Services\Sla;

use App\Models\Holiday;

final class HolidayRepository
{
    private const CACHE_KEY = 'sla.holidays';

    public function __construct(private readonly SlaRulesCache $cache) {}

    /** @return list<string> */
    public function dates(): array
    {
        /** @var list<string> $dates */
        $dates = $this->cache->remember(self::CACHE_KEY, static fn (): array => Holiday::query()
            ->orderBy('date')
            ->get(['date'])
            ->map(static fn (Holiday $holiday): string => $holiday->date->format('Y-m-d'))
            ->values()
            ->all());

        return $dates;
    }

    public function flush(): void
    {
        $this->cache->invalidate(self::CACHE_KEY);
    }
}
