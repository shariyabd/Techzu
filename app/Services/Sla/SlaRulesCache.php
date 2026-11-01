<?php

declare(strict_types=1);

namespace App\Services\Sla;

use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Str;

final class SlaRulesCache
{
    public function __construct(private readonly CacheRepository $cache) {}

    /**
     * @template TData
     *
     * @param  Closure(): TData  $compute
     * @return TData
     */
    public function remember(string $key, Closure $compute): mixed
    {
        $version = $this->version($key);
        $stored = $this->cache->get($key);

        if (is_array($stored) && ($stored['version'] ?? null) === $version && array_key_exists('data', $stored)) {
            return $stored['data'];
        }

        $data = $compute();
        $this->cache->forever($key, ['version' => $version, 'data' => $data]);

        return $data;
    }

    public function invalidate(string $key): void
    {
        $this->cache->forever(self::versionKey($key), (string) Str::ulid());
    }

    private function version(string $key): string
    {
        return (string) $this->cache->rememberForever(self::versionKey($key), static fn (): string => (string) Str::ulid());
    }

    private static function versionKey(string $key): string
    {
        return $key.':version';
    }
}
