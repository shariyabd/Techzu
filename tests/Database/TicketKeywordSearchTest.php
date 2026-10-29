<?php

declare(strict_types=1);

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tickets\TicketFilter;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->admin = User::factory()->admin()->create();
    $this->customer = User::factory()->customer()->create();
    $this->printer = Ticket::factory()->status(TicketStatus::Open)->create([
        'subject' => 'Printer jammed on floor three',
        'description' => 'Paper stuck in tray two.',
        'customer_id' => $this->customer->id,
        'created_by_id' => $this->customer->id,
    ]);
    $this->invoice = Ticket::factory()->status(TicketStatus::Open)->create([
        'subject' => 'Invoice duplicated',
        'description' => 'The printer driver also crashed after the update.',
    ]);
    $this->vpn = Ticket::factory()->status(TicketStatus::Open)->create([
        'subject' => 'Cannot reach VPN',
        'description' => 'Connection drops every hour.',
    ]);
    $this->search = fn (string $q, ?User $viewer = null): array => app(TicketFilter::class)
        ->apply(Ticket::query(), ['q' => $q], $viewer ?? $this->admin, CarbonImmutable::now())
        ->pluck('number')
        ->sort()
        ->values()
        ->all();
    $this->numbers = fn (Ticket ...$tickets): array => collect($tickets)->pluck('number')->sort()->values()->all();
});

it('finds keyword matches in subject or description', function (): void {
    expect(($this->search)('printer'))->toBe(($this->numbers)($this->printer, $this->invoice))
        ->and(($this->search)('invoice'))->toBe(($this->numbers)($this->invoice));
});

it('matches word prefixes', function (): void {
    expect(($this->search)('print'))->toBe(($this->numbers)($this->printer, $this->invoice))
        ->and(($this->search)('conn'))->toBe(($this->numbers)($this->vpn));
});

it('neutralises boolean operators and unbalanced quotes typed by the user', function (): void {
    expect(($this->search)('+printer -invoice'))->toBe(($this->numbers)($this->printer, $this->invoice))
        ->and(($this->search)('"printer (jam'))->toBe(($this->numbers)($this->printer, $this->invoice))
        ->and(($this->search)('*@~<>'))->toHaveCount(3);
});

it('still applies visibility to keyword results', function (): void {
    expect(($this->search)('printer', $this->customer))->toBe(($this->numbers)($this->printer));
});
