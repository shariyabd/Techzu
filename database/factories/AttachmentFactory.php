<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Attachment> */
class AttachmentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'reply_id' => null,
            'uploaded_by' => User::factory()->customer(),
            'disk' => 'local',
            'path' => fn (array $attributes): string => sprintf('attachments/%d/%s.pdf', $attributes['ticket_id'], Str::uuid()->toString()),
            'original_name' => fake()->word().'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(1_000, 500_000),
            'is_internal' => false,
        ];
    }
}
