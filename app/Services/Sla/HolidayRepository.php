<?php

declare(strict_types=1);

namespace App\Services\Sla;

use App\Models\Holiday;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

final class HolidayRepository
{
    private const CACHE_KEY = 'sla.holidays';

    public function __construct(private readonly CacheRepository $cache) {}

    /** @return list<string> */
    public function dates(): array
    {
        /** @var list<string> $dates */
        $dates = $this->cache->rememberForever(self::CACHE_KEY, static fn (): array => Holiday::query()
            ->orderBy('date')
            ->get(['date'])
            ->map(static fn (Holiday $holiday): string => $holiday->date->format('Y-m-d'))
            ->values()
            ->all());

        return $dates;
    }

    public function flush(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }
}
