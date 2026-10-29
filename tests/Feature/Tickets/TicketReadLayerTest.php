<?php

declare(strict_types=1);

use App\Enums\TicketStatus;
use App\Http\Resources\V1\TicketResource;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Services\Tickets\TicketListQuery;
use App\Services\Tickets\TicketPageData;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->now = CarbonImmutable::parse('2026-11-01 03:00:00', 'UTC');
    $this->travelTo($this->now);
    $this->customer = User::factory()->customer()->create();
    $this->otherCustomer = User::factory()->customer()->create();
    $this->agent = User::factory()->agent()->create();
    $this->admin = User::factory()->admin()->create();
});

it('lists only the viewer\'s visible tickets for the requested scope, newest first', function (): void {
    $older = Ticket::factory()->status(TicketStatus::Open)->create(['customer_id' => $this->customer->id, 'created_at' => $this->now->subDay()]);
    $newer = Ticket::factory()->status(TicketStatus::InProgress)->create(['customer_id' => $this->customer->id, 'created_at' => $this->now]);
    $resolved = Ticket::factory()->status(TicketStatus::Resolved)->create(['customer_id' => $this->customer->id, 'created_at' => $this->now->subHour()]);
    $closed = Ticket::factory()->status(TicketStatus::Closed)->create(['customer_id' => $this->customer->id, 'created_at' => $this->now]);
    Ticket::factory()->status(TicketStatus::Open)->create(['customer_id' => $this->otherCustomer->id]);

    $query = app(TicketListQuery::class);

    $active = $query->paginate($this->customer, ['scope' => 'active'], $this->now);
    expect(collect($active->items())->pluck('id')->all())->toBe([$newer->id, $older->id]);

    $finished = $query->paginate($this->customer, ['scope' => 'resolved'], $this->now);
    expect(collect($finished->items())->pluck('id')->all())->toBe([$closed->id, $resolved->id]);

    expect($query->paginate($this->agent, [], $this->now)->total())->toBe(3);
});

it('does not count internal replies for customers', function (): void {
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['customer_id' => $this->customer->id]);
    TicketReply::factory()->create(['ticket_id' => $ticket->id, 'author_id' => $this->agent->id, 'is_internal' => false]);
    TicketReply::factory()->internal()->create(['ticket_id' => $ticket->id, 'author_id' => $this->agent->id]);

    $forCustomer = app(TicketListQuery::class)->paginate($this->customer, [], $this->now)->items()[0];
    $forAgent = app(TicketListQuery::class)->paginate($this->agent, [], $this->now)->items()[0];

    expect($forCustomer->replies_count)->toBe(1)
        ->and($forAgent->replies_count)->toBe(2);
});

it('loads only visible replies and attachments for the show page', function (): void {
    $ticket = Ticket::factory()->create(['customer_id' => $this->customer->id]);
    $public = TicketReply::factory()->create(['ticket_id' => $ticket->id, 'author_id' => $this->agent->id, 'is_internal' => false]);
    $internal = TicketReply::factory()->internal()->create(['ticket_id' => $ticket->id, 'author_id' => $this->agent->id]);
    Attachment::factory()->create(['ticket_id' => $ticket->id, 'reply_id' => $internal->id, 'is_internal' => true]);
    $visible = Attachment::factory()->create(['ticket_id' => $ticket->id, 'reply_id' => null, 'is_internal' => false]);
    $hidden = Attachment::factory()->create(['ticket_id' => $ticket->id, 'reply_id' => null, 'is_internal' => true]);
    $publicReplyVisible = Attachment::factory()->create(['ticket_id' => $ticket->id, 'reply_id' => $public->id, 'is_internal' => false]);
    $publicReplyHidden = Attachment::factory()->create(['ticket_id' => $ticket->id, 'reply_id' => $public->id, 'is_internal' => true]);

    $forCustomer = app(TicketPageData::class)->loadForShow($ticket->fresh(), $this->customer);
    expect($forCustomer->replies->pluck('id')->all())->toBe([$public->id])
        ->and($forCustomer->attachments->pluck('id')->all())->toBe([$visible->id])
        ->and($forCustomer->replies->first()->attachments->pluck('id')->all())->toBe([$publicReplyVisible->id]);

    $forAgent = app(TicketPageData::class)->loadForShow($ticket->fresh(), $this->agent);
    expect($forAgent->replies->pluck('id')->all())->toBe([$public->id, $internal->id])
        ->and($forAgent->replies->last()->attachments)->toHaveCount(1)
        ->and($forAgent->attachments->pluck('id')->all())->toBe([$visible->id, $hidden->id])
        ->and($forAgent->replies->first()->attachments->pluck('id')->all())->toBe([$publicReplyVisible->id, $publicReplyHidden->id]);
});

it('exposes the TicketCan abilities with allowed statuses', function (): void {
    $resolvedOwn = Ticket::factory()->status(TicketStatus::Resolved)->create(['customer_id' => $this->customer->id]);
    $open = Ticket::factory()->status(TicketStatus::Open)->create();

    $customerCan = TicketResource::abilities($this->customer, $resolvedOwn);
    expect(array_keys($customerCan))->toBe(['update', 'changeStatus', 'changePriority', 'claim', 'assign', 'reply', 'addInternalNote', 'allowedStatuses'])
        ->and($customerCan['allowedStatuses'])->toEqualCanonicalizing(['closed', 'in_progress'])
        ->and($customerCan['assign'])->toBeFalse()
        ->and($customerCan['addInternalNote'])->toBeFalse();

    $adminCan = TicketResource::abilities($this->admin, $open);
    expect($adminCan['allowedStatuses'])->toEqualCanonicalizing(['in_progress', 'waiting_for_customer', 'resolved'])
        ->and($adminCan['assign'])->toBeTrue();
});

it('offers claim only on unassigned tickets', function (): void {
    $unassigned = Ticket::factory()->status(TicketStatus::Open)->create(['assigned_agent_id' => null]);
    $assigned = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($this->agent)->create();

    expect(TicketResource::abilities($this->agent, $unassigned)['claim'])->toBeTrue()
        ->and(TicketResource::abilities($this->admin, $assigned)['claim'])->toBeFalse()
        ->and(TicketResource::abilities($this->customer, $unassigned)['claim'])->toBeFalse();
});
