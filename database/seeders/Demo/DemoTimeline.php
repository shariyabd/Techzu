<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;

final class DemoTimeline
{
    /** @var list<array{at: CarbonImmutable, order: int, run: Closure(): void}> */
    private array $entries = [];

    /** @param Closure(): void $run */
    public function at(CarbonImmutable $moment, Closure $run): void
    {
        $this->entries[] = ['at' => $moment->startOfSecond(), 'order' => count($this->entries), 'run' => $run];
    }

    public function earliest(): ?CarbonImmutable
    {
        $moments = array_map(static fn (array $entry): CarbonImmutable => $entry['at'], $this->entries);

        return $moments === [] ? null : min($moments);
    }

    public function run(): void
    {
        $entries = $this->entries;

        usort($entries, static fn (array $a, array $b): int => [$a['at']->getTimestamp(), $a['order']] <=> [$b['at']->getTimestamp(), $b['order']]);

        foreach ($entries as $entry) {
            self::travelTo($entry['at']);
            ($entry['run'])();
        }

        $this->entries = [];
    }

    public static function travelTo(?CarbonImmutable $moment): void
    {
        Carbon::setTestNow($moment);
        CarbonImmutable::setTestNow($moment);
    }
}
