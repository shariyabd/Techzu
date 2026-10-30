<?php

declare(strict_types=1);

use App\Enums\ActivityType;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Events\TicketReplied;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('attachments.disk', 'local');
    $this->travelTo(CarbonImmutable::parse('2026-11-01 03:00:00', 'UTC'));
    $this->category = Category::factory()->create(['is_active' => true]);
    $this->customer = User::factory()->customer()->create();
    $this->agent = User::factory()->agent([$this->category->id])->create();
    $this->admin = User::factory()->admin()->create();
});

it('maps the ticket index to the Inertia page props', function (): void {
    $own = Ticket::factory()->status(TicketStatus::Open)->create(['customer_id' => $this->customer->id, 'assigned_agent_id' => $this->agent->id]);

    $this->actingAs($this->customer)
        ->get('/tickets')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tickets/Index')
            ->where('scope', 'active')
            ->has('tickets.data', 1)
            ->where('tickets.data.0.number', $own->number)
            ->where('tickets.data.0.category.name', $own->category->name)
            ->where('tickets.data.0.assigned_agent.name', $own->assignedAgent->name)
            ->where('tickets.meta.total', 1)
            ->has('tickets.links')
            ->has('filters'));
});

it('rejects an invalid scope and an oversized page', function (): void {
    $this->actingAs($this->customer)
        ->get('/tickets?scope=everything&per_page=500')
        ->assertSessionHasErrors(['scope', 'per_page']);
});

it('renders the create page with options', function (): void {
    $this->actingAs($this->customer)
        ->get('/tickets/create')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tickets/Create')
            ->where('canCreateOnBehalf', false)
            ->has('options.priorities', 4)
            ->where('options.categories.0.id', $this->category->id));
});

it('stores a ticket for the customer with an attachment', function (): void {
    $response = $this->actingAs($this->customer)->post('/tickets', [
        'subject' => 'Cannot log in',
        'description' => 'Password reset mail never arrives.',
        'priority' => TicketPriority::High->value,
        'category_id' => $this->category->id,
        'attachments' => [UploadedFile::fake()->create('trace.txt', 2, 'text/plain')],
    ]);

    $ticket = Ticket::query()->sole();
    $response->assertRedirect('/tickets/'.$ticket->number)->assertSessionHas('success');

    expect($ticket->customer_id)->toBe($this->customer->id)
        ->and($ticket->attachments()->count())->toBe(1);
});

it('validates the store payload', function (array|Closure $payload, array $errors): void {
    $base = [
        'subject' => 'S',
        'description' => 'D',
        'priority' => 'low',
        'category_id' => $this->category->id,
    ];

    $this->actingAs($this->customer)
        ->post('/tickets', array_merge($base, $payload instanceof Closure ? $payload() : $payload))
        ->assertSessionHasErrors($errors);

    expect(Ticket::query()->count())->toBe(0);
})->with([
    'inactive category' => [fn () => ['category_id' => Category::factory()->create(['is_active' => false])->id], ['category_id']],
    'customer cannot pick a customer' => [['customer_email' => 'x@example.com'], ['customer_email']],
    'disallowed file type' => [['attachments' => [UploadedFile::fake()->create('run.exe', 1, 'application/x-msdownload')]], ['attachments.0']],
    'file too large' => [['attachments' => [UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf')]], ['attachments.0']],
    'too many files' => [['attachments' => array_map(fn (int $i) => UploadedFile::fake()->create("f{$i}.txt", 1, 'text/plain'), range(1, 6))], ['attachments']],
]);

it('lets an agent create on behalf of a customer by email', function (): void {
    $this->actingAs($this->agent)->post('/tickets', [
        'subject' => 'Phone call',
        'description' => 'Customer called in.',
        'priority' => 'medium',
        'category_id' => $this->category->id,
        'customer_email' => $this->customer->email,
    ])->assertRedirect();

    $ticket = Ticket::query()->sole();
    expect($ticket->customer_id)->toBe($this->customer->id)
        ->and($ticket->created_by_id)->toBe($this->agent->id);
});

it('requires an existing customer email for on-behalf creation', function (): void {
    $this->actingAs($this->agent)->post('/tickets', [
        'subject' => 'S',
        'description' => 'D',
        'priority' => 'low',
        'category_id' => $this->category->id,
        'customer_email' => $this->agent->email,
    ])->assertSessionHasErrors('customer_email');
});

it('shows a ticket to its customer without internal data', function (): void {
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['customer_id' => $this->customer->id, 'category_id' => $this->category->id, 'assigned_agent_id' => $this->agent->id]);
    $public = TicketReply::factory()->create(['ticket_id' => $ticket->id, 'author_id' => $this->agent->id, 'is_internal' => false]);
    $internal = TicketReply::factory()->internal()->create(['ticket_id' => $ticket->id, 'author_id' => $this->agent->id]);
    TicketReplied::dispatch($ticket, $public, $this->agent);
    TicketReplied::dispatch($ticket, $internal, $this->agent);

    $this->actingAs($this->customer)
        ->get('/tickets/'.$ticket->number)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tickets/Show')
            ->where('ticket.number', $ticket->number)
            ->where('ticket.customer.name', $this->customer->name)
            ->where('ticket.category.name', $this->category->name)
            ->where('ticket.assigned_agent.name', $this->agent->name)
            ->where('activities.0.causer.name', $this->agent->name)
            ->has('ticket.sla.state.value')
            ->has('replies', 1)
            ->where('replies.0.id', $public->id)
            ->has('activities', 1)
            ->where('activities.0.type', ActivityType::Replied->value)
            ->where('agents', [])
            ->where('can.assign', false)
            ->where('can.addInternalNote', false)
            ->has('attachments')
            ->has('options.statuses'));
});

it('shows staff the internal data and the agent list', function (): void {
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['customer_id' => $this->customer->id]);
    TicketReply::factory()->internal()->create(['ticket_id' => $ticket->id, 'author_id' => $this->agent->id]);

    $this->actingAs($this->admin)
        ->get('/tickets/'.$ticket->number)
        ->assertInertia(fn (Assert $page) => $page
            ->has('replies', 1)
            ->where('replies.0.is_internal', true)
            ->where('can.assign', true)
            ->where('agents.0.id', $this->agent->id)
            ->where('ticket.can.allowedStatuses', ['in_progress', 'waiting_for_customer', 'resolved']));
});

it('passes the requested page counts to the ticket page', function (): void {
    config(['tickets.per_page.default' => 2]);
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['customer_id' => $this->customer->id]);
    TicketReply::factory()->count(5)->create(['ticket_id' => $ticket->id, 'author_id' => $this->agent->id, 'is_internal' => false]);

    $this->actingAs($this->admin)->get('/tickets/'.$ticket->number.'?replies_pages=2&activities_pages=3')
        ->assertInertia(fn (Assert $page) => $page
            ->where('repliesPages', 2)
            ->where('activitiesPages', 3)
            ->has('replies', 4)
            ->where('repliesHasMore', true));
});

it('lets a customer edit the subject of their own open ticket', function (): void {
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['customer_id' => $this->customer->id, 'subject' => 'Old']);

    $this->actingAs($this->customer)
        ->from('/tickets/'.$ticket->number)
        ->patch('/tickets/'.$ticket->number, ['subject' => 'New'])
        ->assertRedirect('/tickets/'.$ticket->number)
        ->assertSessionHas('success');

    expect($ticket->fresh()?->subject)->toBe('New');
});

it('prohibits a customer from changing the category', function (): void {
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['customer_id' => $this->customer->id]);
    $other = Category::factory()->create();

    $this->actingAs($this->customer)
        ->patch('/tickets/'.$ticket->number, ['category_id' => $other->id])
        ->assertSessionHasErrors('category_id');
});

it('lets the assigned agent resolve a ticket', function (): void {
    $ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($this->agent)->create();

    $this->actingAs($this->agent)
        ->from('/tickets/'.$ticket->number)
        ->patch('/tickets/'.$ticket->number.'/status', ['status' => 'resolved'])
        ->assertRedirect('/tickets/'.$ticket->number);

    expect($ticket->fresh()?->status)->toBe(TicketStatus::Resolved);
});

it('lets an admin change the priority', function (): void {
    $ticket = Ticket::factory()->status(TicketStatus::Open)->priority(TicketPriority::Low)->create();

    $this->actingAs($this->admin)
        ->patch('/tickets/'.$ticket->number.'/priority', ['priority' => 'critical'])
        ->assertRedirect();

    expect($ticket->fresh()?->priority)->toBe(TicketPriority::Critical);
});
