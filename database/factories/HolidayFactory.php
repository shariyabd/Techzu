<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Holiday;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Holiday> */
class HolidayFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'date' => CarbonImmutable::today()->addDays(fake()->unique()->numberBetween(1, 3650))->toDateString(),
            'name' => ucfirst(fake()->words(2, true)),
        ];
    }
}
