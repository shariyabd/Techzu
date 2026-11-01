<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\TicketPriority;
use App\Services\Sla\BusinessCalendar;
use Carbon\CarbonImmutable;
use Faker\Generator;
use Illuminate\Support\Str;
use LogicException;

final class DemoPlanner
{
    private const int LATEST_GAP_MINUTES = 3;

    private const int MAX_SHIFT_ATTEMPTS = 8;

    private const int SEARCH_STEP_MINUTES = 10;

    private const int SEARCH_LIMIT_DAYS = 45;

    private const array PLANS = ['Team', 'Business', 'Growth', 'Professional'];

    private const array BROWSERS = ['Chrome 130', 'Firefox 132', 'Safari 18', 'Edge 130'];

    private const array SYSTEMS = ['macOS Sequoia', 'Windows 11', 'Ubuntu 24.04', 'ChromeOS'];

    private const array DEVICES = ['an iPhone 13', 'a Pixel 8', 'an iPad Air', 'a Galaxy S23'];

    /** @param array<string, int> $resolutionMinutes */
    public function __construct(
        private readonly CarbonImmutable $now,
        private readonly BusinessCalendar $calendar,
        private readonly array $resolutionMinutes,
        private readonly Generator $faker,
        private readonly DemoCast $cast,
    ) {}

    /** @var array<string, int> */
    private array $scenarioUses = [];

    public function prepare(DemoStory $story): void
    {
        $story->variant = $this->scenarioUses[$story->scenario] ?? 0;
        $this->scenarioUses[$story->scenario] = $story->variant + 1;
        $story->tokens = $this->tokens($story);
    }

    /** @return list<CarbonImmutable> */
    public function moments(DemoStory $story): array
    {
        $latest = $this->now->subMinutes(self::LATEST_GAP_MINUTES);

        if ($story->elapsedFraction !== null) {
            $priority = $story->priority ?? DemoScenarios::get($story->scenario)->priority;

            return $this->compress($this->walk($story, $this->startForElapsed($priority, $story->elapsedFraction), false), $latest);
        }

        $daysAgo = $story->daysAgo ?? throw new LogicException('A demo story needs a creation moment.');
        $start = $this->now->subMinutes((int) round($daysAgo * 1440) + $this->faker->numberBetween(-45, 45));
        $moments = $this->walk($story, $start, true);

        for ($attempt = 1; $attempt <= self::MAX_SHIFT_ATTEMPTS && self::last($moments)->greaterThan($latest); $attempt++) {
            $start = $start->subSeconds((int) $latest->diffInSeconds(self::last($moments), true))->subMinutes(45 * $attempt);
            $moments = $this->walk($story, $start, true);
        }

        return $this->compress($moments, $latest);
    }

    /** @return list<CarbonImmutable> */
    private function walk(DemoStory $story, CarbonImmutable $start, bool $snapStaff): array
    {
        $moment = $snapStaff && $story->creator !== null ? $this->snap($start) : $start;
        $moments = [$moment];

        foreach ($story->steps as $step) {
            $moment = $moment->addMinutes($step->afterMinutes);

            if ($snapStaff && $step->type->isStaffAction()) {
                $moment = $this->snap($moment);
            }

            $moments[] = $moment;
        }

        return $moments;
    }

    private function snap(CarbonImmutable $moment): CarbonImmutable
    {
        if ($this->calendar->isBusinessTime($moment)) {
            return $moment;
        }

        return $this->calendar->addBusinessMinutes($moment, $this->faker->numberBetween(4, 40));
    }

    /**
     * @param  list<CarbonImmutable>  $moments
     * @return list<CarbonImmutable>
     */
    private function compress(array $moments, CarbonImmutable $latest): array
    {
        $first = $moments[0];
        $last = self::last($moments);

        if ($last->lessThanOrEqualTo($latest)) {
            return $moments;
        }

        $span = max(1, (int) $first->diffInSeconds($last, true));
        $room = max(count($moments), (int) $first->diffInSeconds($latest, true));

        return array_map(
            static fn (CarbonImmutable $moment): CarbonImmutable => $first->addSeconds(intdiv((int) $first->diffInSeconds($moment, true) * $room, $span)),
            $moments,
        );
    }

    private function startForElapsed(TicketPriority $priority, float $fraction): CarbonImmutable
    {
        $target = (int) round($this->resolutionMinutes[$priority->value] * $fraction);
        $limit = $this->now->subDays(self::SEARCH_LIMIT_DAYS);
        $cursor = $this->now->subMinutes(self::SEARCH_STEP_MINUTES);

        while ($cursor->greaterThan($limit) && $this->calendar->businessMinutesBetween($cursor, $this->now) < $target) {
            $cursor = $cursor->subMinutes(self::SEARCH_STEP_MINUTES);
        }

        return $cursor->startOfMinute();
    }

    /** @return array<string, string> */
    private function tokens(DemoStory $story): array
    {
        $faker = $this->faker;
        $date = $this->now->subDays($faker->numberBetween(2, 20));

        return [
            '{month}' => $date->format('F'),
            '{date}' => $date->format('j F'),
            '{invoice}' => 'INV-'.$faker->numberBetween(20410, 28999),
            '{amount}' => '$'.number_format($faker->numberBetween(1900, 89000) / 100, 2),
            '{plan}' => $faker->randomElement(self::PLANS),
            '{seats}' => (string) $faker->numberBetween(3, 40),
            '{count}' => number_format($faker->numberBetween(12, 140) * 100),
            '{browser}' => $faker->randomElement(self::BROWSERS),
            '{os}' => $faker->randomElement(self::SYSTEMS),
            '{device}' => $faker->randomElement(self::DEVICES),
            '{time}' => sprintf('%d:%02d', $faker->numberBetween(9, 16), $faker->randomElement([0, 15, 30, 45])),
            '{reference}' => strtoupper($faker->bothify('??#####')),
            '{domain}' => Str::slug($this->cast->company($story->customer), '').'.example',
        ];
    }

    /** @param list<CarbonImmutable> $moments */
    private static function last(array $moments): CarbonImmutable
    {
        return $moments[count($moments) - 1];
    }
}
