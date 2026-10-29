<?php

declare(strict_types=1);

use App\Actions\Assignment\ClaimTicket;
use App\Enums\ActivityType;
use App\Enums\TicketStatus;
use App\Exceptions\Domain\TicketAlreadyClaimed;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

afterEach(function (): void {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
});

it('blocks a competing claim on the row lock and rejects it after the first claim commits', function (): void {
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['assigned_agent_id' => null]);
    $first = User::factory()->agent()->create();
    $second = User::factory()->agent()->create();
    concurrentConnection()->statement('SET SESSION innodb_lock_wait_timeout = 1');
    $staleTicket = Ticket::on('mysql_concurrent')->findOrFail($ticket->id);
    $secondAgent = User::on('mysql_concurrent')->findOrFail($second->id);

    DB::beginTransaction();
    app(ClaimTicket::class)->handle($first, $ticket);

    $blocked = null;

    try {
        app(ClaimTicket::class)->handle($secondAgent, $staleTicket);
    } catch (QueryException $exception) {
        $blocked = $exception;
    }

    DB::commit();

    expect($blocked?->errorInfo[1] ?? null)->toBe(1205)
        ->and($staleTicket->assigned_agent_id)->toBeNull()
        ->and(fn () => app(ClaimTicket::class)->handle($secondAgent, $staleTicket))->toThrow(TicketAlreadyClaimed::class);

    $row = DB::table('tickets')->where('id', $ticket->id)->first();
    $claims = Activity::query()
        ->where('subject_type', $ticket->getMorphClass())
        ->where('subject_id', $ticket->id)
        ->where('event', ActivityType::Claimed->value)
        ->count();

    expect($row->assigned_agent_id)->toBe($first->id)
        ->and($row->status)->toBe(TicketStatus::InProgress->value)
        ->and($claims)->toBe(1);
});
