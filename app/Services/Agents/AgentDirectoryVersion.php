<?php

declare(strict_types=1);

namespace App\Services\Agents;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AgentDirectoryVersion
{
    private const string KEY = 'agents:directory-version';

    public function __construct(private readonly Cache $cache) {}

    public function current(): string
    {
        return (string) $this->cache->rememberForever(self::KEY, static fn (): string => (string) Str::ulid());
    }

    public function rotate(): void
    {
        $this->cache->forever(self::KEY, (string) Str::ulid());
    }

    public function rotateAfterCommit(): void
    {
        DB::afterCommit(fn () => $this->rotate());
    }
}
