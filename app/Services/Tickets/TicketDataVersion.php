<?php

declare(strict_types=1);

namespace App\Services\Tickets;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Str;

final class TicketDataVersion
{
    private const string KEY = 'tickets:data-version';

    public function __construct(private readonly Cache $cache) {}

    public function current(): string
    {
        return (string) $this->cache->rememberForever(self::KEY, static fn (): string => (string) Str::ulid());
    }

    public function rotate(): void
    {
        $this->cache->forever(self::KEY, (string) Str::ulid());
    }
}
