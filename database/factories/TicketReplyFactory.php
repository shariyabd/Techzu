<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TicketReply> */
class TicketReplyFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'author_id' => fn (array $attributes): int => Ticket::query()->findOrFail($attributes['ticket_id'])->customer_id,
            'body' => fake()->paragraph(),
            'is_internal' => false,
        ];
    }

    public function internal(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_internal' => true,
            'author_id' => User::factory()->agent(),
        ]);
    }
}
