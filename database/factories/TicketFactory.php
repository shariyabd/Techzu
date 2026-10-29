<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EscalationLevel;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Ticket> */
class TicketFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $now = CarbonImmutable::now();

        return [
            'number' => sprintf('%s-%d-%06d', config('tickets.number_prefix', 'TKT'), $now->year, fake()->unique()->numberBetween(900000, 999999)),
            'subject' => fake()->sentence(6),
            'description' => fake()->paragraphs(2, true),
            'priority' => TicketPriority::Medium,
            'status' => TicketStatus::Open,
            'category_id' => Category::factory(),
            'customer_id' => User::factory()->customer(),
            'created_by_id' => fn (array $attributes): mixed => $attributes['customer_id'],
            'assigned_agent_id' => null,
            'assigned_at' => null,
            'external_reference' => null,
            'sla_started_at' => $now->subHour(),
            'sla_due_at' => $now->addHours(23),
            'sla_warning_at' => $now->addHours(17),
            'sla_paused_at' => null,
            'escalation_level' => EscalationLevel::None,
            'escalated_at' => null,
            'resolved_at' => null,
            'closed_at' => null,
        ];
    }

    public function assignedTo(User $agent): static
    {
        return $this->state(fn (array $attributes): array => [
            'assigned_agent_id' => $agent->id,
            'assigned_at' => CarbonImmutable::now(),
        ]);
    }

    public function status(TicketStatus $status): static
    {
        return $this->state(function (array $attributes) use ($status): array {
            $now = CarbonImmutable::now();

            return [
                'status' => $status,
                'sla_paused_at' => $status === TicketStatus::WaitingForCustomer ? $now->subMinutes(5) : null,
                'resolved_at' => in_array($status, TicketStatus::finished(), true) ? $now->subMinutes(30) : null,
                'closed_at' => $status === TicketStatus::Closed ? $now->subMinutes(10) : null,
            ];
        });
    }

    public function priority(TicketPriority $priority): static
    {
        return $this->state(fn (array $attributes): array => [
            'priority' => $priority,
        ]);
    }

    public function breached(): static
    {
        return $this->state(function (array $attributes): array {
            $now = CarbonImmutable::now();

            return [
                'sla_started_at' => $now->subHours(10),
                'sla_warning_at' => $now->subHours(2),
                'sla_due_at' => $now->subMinutes(30),
            ];
        });
    }

    public function approaching(): static
    {
        return $this->state(function (array $attributes): array {
            $now = CarbonImmutable::now();

            return [
                'sla_started_at' => $now->subHours(7),
                'sla_warning_at' => $now->subMinutes(10),
                'sla_due_at' => $now->addMinutes(50),
            ];
        });
    }

    public function paused(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TicketStatus::WaitingForCustomer,
            'sla_paused_at' => CarbonImmutable::now()->subMinutes(5),
        ]);
    }
}
