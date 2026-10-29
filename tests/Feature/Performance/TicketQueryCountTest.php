<?php

declare(strict_types=1);

use App\Enums\TicketStatus;
use App\Events\TicketReplied;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function ticketQueryCount(callable $request): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $request();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

function ticketWithConversation(User $agent, int $replies): Ticket
{
    $ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($agent)->create();

    for ($i = 0; $i < $replies; $i++) {
        $reply = TicketReply::factory()->create(['ticket_id' => $ticket->id, 'author_id' => User::factory()->agent()->create()->id, 'is_internal' => $i % 2 === 1]);
        Attachment::factory()->create(['ticket_id' => $ticket->id, 'reply_id' => $reply->id, 'is_internal' => (bool) $reply->is_internal]);
        TicketReplied::dispatch($ticket, $reply, $agent);
    }

    return $ticket;
}

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->agent = User::factory()->agent()->create();
});

it('renders the web ticket index with a constant number of queries', function (): void {
    $this->actingAs($this->admin);
    Ticket::factory()->status(TicketStatus::Open)->count(2)->sequence(fn () => ['assigned_agent_id' => User::factory()->agent()->create()->id])->create();
    $this->get('/tickets')->assertOk();

    $small = ticketQueryCount(fn () => $this->get('/tickets')->assertOk());

    Ticket::factory()->status(TicketStatus::Open)->count(15)->sequence(fn () => ['assigned_agent_id' => User::factory()->agent()->create()->id])->create();
    $large = ticketQueryCount(fn () => $this->get('/tickets')->assertOk());

    expect($large)->toBe($small)
        ->and($small)->toBeLessThanOrEqual(12);
});

it('serves the API ticket index with a constant number of queries', function (): void {
    Sanctum::actingAs($this->admin);
    Ticket::factory()->status(TicketStatus::Open)->count(2)->create();
    $this->getJson('/api/v1/tickets')->assertOk();

    $small = ticketQueryCount(fn () => $this->getJson('/api/v1/tickets')->assertOk());
    Ticket::factory()->status(TicketStatus::Open)->count(15)->create();
    $large = ticketQueryCount(fn () => $this->getJson('/api/v1/tickets')->assertOk());

    expect($large)->toBe($small);
});

it('renders the ticket show page with a constant number of queries', function (): void {
    $this->actingAs($this->admin);
    $small = ticketWithConversation($this->agent, 2);
    $large = ticketWithConversation($this->agent, 12);
    $this->get('/tickets/'.$small->number)->assertOk();

    $smallCount = ticketQueryCount(fn () => $this->get('/tickets/'.$small->number)->assertOk());
    $largeCount = ticketQueryCount(fn () => $this->get('/tickets/'.$large->number)->assertOk());

    expect($largeCount)->toBe($smallCount)
        ->and($smallCount)->toBeLessThanOrEqual(20);
});
