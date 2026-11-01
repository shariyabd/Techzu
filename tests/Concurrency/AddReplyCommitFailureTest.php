<?php

declare(strict_types=1);

use App\Actions\Conversation\AddReply;
use App\Enums\TicketStatus;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

afterEach(function (): void {
    $pdo = DB::connection()->getPdo();

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
});

it('discards the staged files when the reply transaction fails at commit', function (): void {
    Storage::fake(config('attachments.disk'));
    $agent = User::factory()->agent()->create();
    $ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($agent)->create();
    Event::listen(TransactionCommitting::class, static function (): void {
        throw new RuntimeException('Deadlock found when trying to get lock on commit');
    });

    expect(fn () => app(AddReply::class)->handle($agent, $ticket, 'Lost at commit.', false, [
        UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
    ]))->toThrow(RuntimeException::class, 'Deadlock found');

    DB::connection()->getPdo()->rollBack();

    expect(Storage::disk(config('attachments.disk'))->allFiles())->toBeEmpty()
        ->and(TicketReply::query()->count())->toBe(0)
        ->and(Attachment::query()->count())->toBe(0);
});
