<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class DemoSeeder extends Seeder
{
    private const int TICKETS = 60;

    private const array STATUS_CYCLE = [
        TicketStatus::Open,
        TicketStatus::InProgress,
        TicketStatus::WaitingForCustomer,
        TicketStatus::InProgress,
        TicketStatus::Resolved,
        TicketStatus::Closed,
    ];

    public function run(): void
    {
        if (User::query()->where('email', 'admin@example.com')->exists()) {
            return;
        }

        DB::transaction(function (): void {
            $categories = $this->categories();
            $agentsByCategory = $this->agents($categories);
            $customers = $this->customers();

            $this->tickets($categories, $agentsByCategory, $customers);

            User::factory()->admin()->create(['name' => 'Ada Admin', 'email' => 'admin@example.com']);
        });
    }

    /** @return list<Category> */
    private function categories(): array
    {
        return [
            Category::factory()->create(['name' => 'Billing', 'slug' => 'billing']),
            Category::factory()->create(['name' => 'Technical Support', 'slug' => 'technical-support']),
            Category::factory()->create(['name' => 'General Inquiry', 'slug' => 'general-inquiry']),
        ];
    }

    /**
     * @param  list<Category>  $categories
     * @return array<int, list<User>>
     */
    private function agents(array $categories): array
    {
        [$billing, $technical, $general] = $categories;

        $profiles = [
            1 => [[$billing->id, $technical->id], true],
            2 => [[$technical->id], true],
            3 => [[$general->id], true],
            4 => [[$billing->id, $general->id], false],
        ];

        $byCategory = [];

        foreach ($profiles as $n => [$categoryIds, $available]) {
            $factory = User::factory()->agent($categoryIds);

            if (! $available) {
                $factory = $factory->unavailable();
            }

            $agent = $factory->create(['name' => "Agent {$n}", 'email' => "agent{$n}@example.com"]);

            if ($available) {
                foreach ($categoryIds as $categoryId) {
                    $byCategory[$categoryId][] = $agent;
                }
            }
        }

        return $byCategory;
    }

    /** @return list<User> */
    private function customers(): array
    {
        $customers = [];

        foreach (range(1, 5) as $n) {
            $customers[] = User::factory()->customer()->create(['name' => "Customer {$n}", 'email' => "customer{$n}@example.com"]);
        }

        return $customers;
    }

    /**
     * @param  list<Category>  $categories
     * @param  array<int, list<User>>  $agentsByCategory
     * @param  list<User>  $customers
     */
    private function tickets(array $categories, array $agentsByCategory, array $customers): void
    {
        $prefix = (string) config('tickets.number_prefix', 'TKT');
        $now = CarbonImmutable::now();
        $priorities = TicketPriority::cases();

        for ($i = 1; $i <= self::TICKETS; $i++) {
            $category = $categories[$i % count($categories)];
            $customer = $customers[$i % count($customers)];
            $status = self::STATUS_CYCLE[$i % count(self::STATUS_CYCLE)];

            $factory = Ticket::factory()
                ->priority($priorities[$i % count($priorities)])
                ->status($status)
                ->state([
                    'number' => sprintf('%s-%d-%06d', $prefix, $now->year, $i),
                    'category_id' => $category->id,
                    'customer_id' => $customer->id,
                    'created_by_id' => $customer->id,
                    'created_at' => $now->subHours((self::TICKETS - $i) * 3),
                ]);

            if ($status !== TicketStatus::Open) {
                $pool = $agentsByCategory[$category->id];
                $factory = $factory->assignedTo($pool[$i % count($pool)]);
            }

            if ($i % 10 === 3) {
                $factory = $factory->breached();
            } elseif ($i % 10 === 7) {
                $factory = $factory->approaching();
            }

            $factory->create();
        }

        DB::table('ticket_sequences')->updateOrInsert(
            ['year' => $now->year],
            ['last_number' => self::TICKETS, 'created_at' => $now, 'updated_at' => $now],
        );
    }
}
