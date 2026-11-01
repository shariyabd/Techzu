<?php

declare(strict_types=1);

namespace App\Services\Tickets;

use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;

final class TicketDataCache
{
    public function __construct(
        private readonly Cache $cache,
        private readonly TicketDataVersion $version,
    ) {}

    /**
     * @template TData
     *
     * @param  Closure(): TData  $compute
     * @return TData
     */
    public function remember(string $key, int $ttlSeconds, Closure $compute, string $variant = ''): mixed
    {
        $stamp = $this->version->current().'|'.$variant;
        $stored = $this->cache->get($key);

        if (is_array($stored) && ($stored['version'] ?? null) === $stamp && array_key_exists('data', $stored)) {
            return $stored['data'];
        }

        $data = $compute();
        $this->cache->put($key, ['version' => $stamp, 'data' => $data], max(1, $ttlSeconds));

        return $data;
    }
}
