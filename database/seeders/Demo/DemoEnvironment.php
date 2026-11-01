<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Config\Repository as Config;

final class DemoEnvironment
{
    private const array OVERRIDES = [
        'queue.default' => 'sync',
        'mail.default' => 'array',
        'broadcasting.default' => 'null',
        'tickets.auto_assign' => false,
    ];

    public function __construct(private readonly Config $config) {}

    /** @param Closure(): void $seed */
    public function isolate(Closure $seed): void
    {
        $previousConfig = [];

        foreach (array_keys(self::OVERRIDES) as $key) {
            $previousConfig[$key] = $this->config->get($key);
        }

        $previousNow = CarbonImmutable::hasTestNow() ? CarbonImmutable::now() : null;
        $this->config->set(self::OVERRIDES);

        try {
            $seed();
        } finally {
            $this->config->set($previousConfig);
            DemoTimeline::travelTo($previousNow);
        }
    }
}
