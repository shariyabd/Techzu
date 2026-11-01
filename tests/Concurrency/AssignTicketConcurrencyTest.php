<?php

declare(strict_types=1);

use App\Actions\Assignment\AssignTicket;
use App\Actions\Assignment\ClaimTicket;
use App\Enums\TicketStatus;
use App\Exceptions\Domain\TicketAlreadyClaimed;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

afterEach(function (): void {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
});

it('makes a racing claim wait for a manual assignment and then lose', function (): void {
    $category = Category::factory()->create();
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['assigned_agent_id' => null, 'category_id' => $category->id]);
    $admin = User::factory()->admin()->create();
    $assignee = User::factory()->agent([$category->id])->create();
    $claimer = User::factory()->agent()->create();
    concurrentConnection()->statement('SET SESSION innodb_lock_wait_timeout = 1');
    $staleTicket = Ticket::on('mysql_concurrent')->findOrFail($ticket->id);
    $staleClaimer = User::on('mysql_concurrent')->findOrFail($claimer->id);

    DB::beginTransaction();
    app(AssignTicket::class)->handle($admin, $ticket, $assignee);

    $blocked = null;

    try {
        app(ClaimTicket::class)->handle($staleClaimer, $staleTicket);
    } catch (QueryException $exception) {
        $blocked = $exception;
    }

    DB::commit();

    expect($blocked?->errorInfo[1] ?? null)->toBe(1205)
        ->and(fn () => app(ClaimTicket::class)->handle($staleClaimer, $staleTicket))->toThrow(TicketAlreadyClaimed::class);

    $row = DB::table('tickets')->where('id', $ticket->id)->first();

    expect($row->assigned_agent_id)->toBe($assignee->id)
        ->and($row->status)->toBe(TicketStatus::InProgress->value);
});
