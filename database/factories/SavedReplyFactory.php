<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SavedReply;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SavedReply> */
class SavedReplyFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'category_id' => null,
            'title' => ucfirst(fake()->words(3, true)),
            'body' => 'Hi {customer_first_name}, '.fake()->sentence(),
        ];
    }

    public function personal(User $owner): static
    {
        return $this->state(fn (): array => ['user_id' => $owner->id]);
    }

    public function shared(): static
    {
        return $this->state(fn (): array => ['user_id' => null]);
    }
}
