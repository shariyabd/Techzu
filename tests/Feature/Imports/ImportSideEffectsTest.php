<?php

declare(strict_types=1);

use App\Actions\Imports\StartTicketImport;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\ImportedTicketsAssignedNotification;
use App\Notifications\TicketAssignedNotification;
use App\Notifications\TicketImportFinishedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

it('auto-assigns the active imported rows only when the import opts in, with one summary per agent instead of per-ticket mail', function (bool $autoAssignEnabled, bool $importOptsIn, bool $eligibleAgents, array $assigned): void {
    Notification::fake();
    Storage::fake((string) config('imports.disk'));
    config(['tickets.auto_assign' => $autoAssignEnabled]);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 06:00:00', 'UTC'));
    $category = Category::factory()->create(['slug' => 'hardware', 'is_active' => true]);
    $agents = User::factory()->agent($eligibleAgents ? [$category->id] : [])->count(2)->create();
    $admin = User::factory()->admin()->create();
    User::factory()->customer()->create(['email' => 'customer@example.com']);
    $csv = implode("\n", [
        'external_reference,subject,description,priority,category,customer_email,status,created_at',
        'EXT-1,Open one,Body,low,hardware,customer@example.com,open,',
        'EXT-2,In progress,Body,low,hardware,customer@example.com,in_progress,',
        'EXT-3,Waiting,Body,low,hardware,customer@example.com,waiting_for_customer,',
        'EXT-4,Resolved one,Body,low,hardware,customer@example.com,resolved,',
        'EXT-5,Closed one,Body,low,hardware,customer@example.com,closed,2026-10-01 10:00',
    ])."\n";

    $import = app(StartTicketImport::class)->handle($admin, UploadedFile::fake()->createWithContent('tickets.csv', $csv), $importOptsIn)->refresh();

    expect($import->succeeded_rows)->toBe(5)
        ->and(Ticket::query()->whereNotNull('assigned_agent_id')->orderBy('external_reference')->pluck('external_reference')->all())->toBe($assigned);
    Notification::assertSentTo($admin, TicketImportFinishedNotification::class);
    Notification::assertNotSentTo($agents, TicketAssignedNotification::class);

    $summaries = 0;

    foreach ($agents as $agent) {
        $received = Ticket::query()->where('assigned_agent_id', $agent->id)->count();
        Notification::assertSentToTimes($agent, ImportedTicketsAssignedNotification::class, $received > 0 ? 1 : 0);

        if ($received > 0) {
            $summaries++;
            Notification::assertSentTo($agent, ImportedTicketsAssignedNotification::class, fn (ImportedTicketsAssignedNotification $notification, array $channels): bool => $notification->count === $received && $channels === ['mail', 'database']);
        }
    }

    Notification::assertCount(1 + $summaries);
})->with([
    'opted in' => [true, true, true, ['EXT-1', 'EXT-2', 'EXT-3']],
    'opted in, no eligible agent' => [true, true, false, []],
    'not opted in' => [true, false, true, []],
    'opted in while auto-assignment is switched off' => [false, true, true, []],
]);
