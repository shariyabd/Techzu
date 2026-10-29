<?php

declare(strict_types=1);

use App\Enums\TicketStatus;
use App\Events\TicketReplied;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Notifications\TicketCreatedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

function queriesDuring(Closure $visit): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $visit();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

function addListRows(string $kind, int $count, User $viewer, Ticket $ticket, Category $category): void
{
    for ($i = 0; $i < $count; $i++) {
        if ($kind === 'users') {
            User::factory()->agent([$category->id])->create();

            continue;
        }

        if ($kind === 'notifications') {
            DB::table('notifications')->insert([
                'id' => (string) Str::uuid(),
                'type' => TicketCreatedNotification::class,
                'notifiable_type' => $viewer->getMorphClass(),
                'notifiable_id' => $viewer->id,
                'data' => json_encode(['ticket_number' => $ticket->number, 'title' => 'Created', 'body' => 'Created', 'url' => '/'], JSON_THROW_ON_ERROR),
                'read_at' => null,
                'created_at' => CarbonImmutable::now(),
                'updated_at' => CarbonImmutable::now(),
            ]);

            continue;
        }

        $author = User::factory()->agent()->create();
        $reply = TicketReply::factory()->create(['ticket_id' => $ticket->id, 'author_id' => $author->id, 'is_internal' => false]);
        Attachment::factory()->create(['ticket_id' => $ticket->id, 'reply_id' => $reply->id, 'is_internal' => false]);

        if ($kind === 'activities') {
            TicketReplied::dispatch($ticket, $reply, $author);
        }
    }
}

dataset('list endpoints', [
    'api replies' => ['replies', 'api.v1.tickets.replies.index', true],
    'api activity timeline' => ['activities', 'api.v1.tickets.activities', true],
    'web inbox' => ['notifications', 'notifications.index', false],
    'api inbox' => ['notifications', 'api.v1.notifications.index', false],
    'web admin users' => ['users', 'admin.users.index', false],
    'api admin users' => ['users', 'api.v1.admin.users.index', false],
]);

it('keeps list endpoints at a constant query count as rows grow', function (string $kind, string $routeName, bool $needsTicket): void {
    $admin = User::factory()->admin()->create();
    $category = Category::factory()->create();
    $ticket = Ticket::factory()
        ->status(TicketStatus::InProgress)
        ->assignedTo(User::factory()->agent([$category->id])->create())
        ->create(['category_id' => $category->id]);
    $url = $needsTicket ? route($routeName, $ticket) : route($routeName);
    $isApi = str_starts_with($routeName, 'api.');

    if ($isApi) {
        Sanctum::actingAs($admin, ['*']);
    } else {
        $this->actingAs($admin);
    }

    $visit = fn () => $isApi ? $this->getJson($url)->assertOk() : $this->get($url)->assertOk();

    addListRows($kind, 2, $admin, $ticket, $category);
    $visit();
    $small = queriesDuring($visit);

    addListRows($kind, 10, $admin, $ticket, $category);
    $large = queriesDuring($visit);

    expect($large)->toBe($small);
})->with('list endpoints');
