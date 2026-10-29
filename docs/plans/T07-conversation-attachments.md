# T07 — Conversation & attachments

> **For agentic workers:** REQUIRED SUB-SKILL: use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax. Work test-first. Run the gate before each commit.

## 1. Header

- **Objective:** let customers and staff converse on a ticket through public replies and staff-only internal notes with validated, privately stored, policy-checked attachments, on both the web (Inertia) and the API.
- **Depends on:** T05 (events, `AttachmentStorer`, `ChangeTicketStatus`, `TicketController@show` props, TS types, component stubs), and transitively T01 (models, factories, `TicketClosed`) and T04 (`TicketPolicy::reply/addInternalNote/viewInternal/view`, `TicketReplyPolicy::view`, `AttachmentPolicy::view`).
- **Wave:** 4a (parallel with T06, T08, T09).
- **TDs implemented:** TD-060 (replies and notes in one table, `is_internal` forced false for customers, files stored before commit with compensating delete), TD-061 (private disk, `File::types()->max()`, streamed download with `nosniff` and `attachment` disposition), TD-024 (attachments inherit `is_internal`), TD-053 (AddReply transaction: reply + attachments + status change), TD-042 (customer reply resumes the SLA through `ChangeTicketStatus`), TD-012 (404 for a customer probing another customer's ticket), TD-120 (three-layer internal-note leakage defence: query scope, Resource, policy-checked download), TD-110 (`TicketClosed` → 422 `ticket_closed`).

## 2. Files

**Create**

| Path | Purpose |
|---|---|
| `app/Actions/Conversation/AddReply.php` | the use case |
| `app/Http/Requests/StoreReplyRequest.php` | shared web/API validation + authorization |
| `app/Http/Requests/IndexRepliesRequest.php` | API replies index (authorization + `per_page`) |
| `app/Services/Conversation/ConversationQuery.php` | the visibility-scoped, eager-loaded replies page (keeps the query out of the controller) |
| `app/Http/Controllers/Web/TicketReplyController.php` | web `store` |
| `app/Http/Controllers/Web/AttachmentDownloadController.php` | web download |
| `app/Http/Controllers/Api/V1/TicketReplyController.php` | API `index`, `store` |
| `app/Http/Controllers/Api/V1/AttachmentDownloadController.php` | API download |
| `app/Http/Resources/V1/TicketReplyResource.php` | the reply resource (second leakage layer) |
| `routes/web/conversation.php` | `tickets.replies.store`, `attachments.download` |
| `routes/api/v1/conversation.php` | `tickets.replies.index`, `tickets.replies.store`, `attachments.download` |
| `resources/js/lib/attachments.ts` | client-side hints (accept list, size and count checks, byte formatting) |
| `tests/Feature/Conversation/AddReplyTest.php` | Action behaviour |
| `tests/Feature/Conversation/ReplyEndpointsTest.php` | web + API store, closed ticket (403 at the policy, 422 on a race), validation, UUID path |
| `tests/Feature/Conversation/ReplyVisibilityTest.php` | leakage: web props, API index, API show, Resource |
| `tests/Feature/Conversation/AttachmentDownloadTest.php` | streamed download, headers, internal-attachment denial |
| `tests/Feature/Authorization/ConversationAuthorizationTest.php` | role × route matrix for this task's routes |

**Modify (replace the T05 stubs; README §4 grants this)**

| Path | Change |
|---|---|
| `resources/js/components/tickets/ConversationPanel.vue` | full replacement of the T05 stub; same props |
| `resources/js/components/tickets/AttachmentList.vue` | full replacement of the T05 stub; same props |

**Must not touch:** `bootstrap/app.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `app/Http/Controllers/Web/TicketController.php`, `app/Http/Controllers/Api/V1/TicketController.php`, `app/Services/Attachments/AttachmentStorer.php`, `app/Actions/Tickets/*`, `app/Events/*`, `app/Policies/*`, `app/Models/*`, `config/attachments.php`, `resources/js/types/tickets.ts`, `resources/js/pages/tickets/Show.vue`, `resources/js/components/tickets/AssignmentPanel.vue` (T06), `tests/Pest.php`, `tests/Support/AuthorizationMatrix.php`, any migration.

## 3. Interfaces

### Consumes

```php
// T01
App\Models\Ticket            // number route key, status TicketStatus, customer_id, replies(), scopeVisibleTo
App\Models\TicketReply       // ticket(), author(), attachments(), scopeVisibleTo(Builder, User); fillable body, is_internal
App\Models\Attachment        // disk, path, original_name, mime_type, size_bytes, is_internal, ticket_id, reply_id
App\Exceptions\Domain\TicketClosed               // 422 'ticket_closed'; T01: __construct(string $message = 'This ticket is closed and no longer accepts changes.')
App\Http\Resources\V1\UserSummaryResource        // id, name
Database\Factories: UserFactory::customer()/agent()/admin(), TicketFactory::status()/assignedTo(), TicketReplyFactory::internal()

// T04 (policies, called only through Gate / $user->can())
TicketPolicy::view(User, Ticket)            // customer on another customer's ticket → Response::denyAsNotFound()
// all ticket abilities return Response; reply/addInternalNote deny (403) on Closed for everyone;
// AttachmentPolicy::view → 404 for an unseen ticket and for an internal attachment the user can't see
TicketPolicy::reply(User, Ticket)
TicketPolicy::addInternalNote(User, Ticket)
TicketPolicy::viewInternal(User, Ticket)
TicketReplyPolicy::view(User, TicketReply)
AttachmentPolicy::view(User, Attachment)

// T05
App\Actions\Tickets\ChangeTicketStatus::handle(?User $actor, Ticket $ticket, TicketStatus $to): Ticket
App\Services\Attachments\AttachmentStorer::storeMany(Ticket $ticket, ?TicketReply $reply, User $uploader, array $files, bool $isInternal): Collection
App\Services\Attachments\AttachmentStorer::deleteFiles(Collection $attachments): void
App\Events\TicketReplied(Ticket $ticket, TicketReply $reply, User $author)   // ShouldDispatchAfterCommit
App\Http\Resources\V1\AttachmentResource      // id, original_name, mime_type, size_bytes, is_internal, download_url, created_at
App\Http\Resources\V1\TicketResource                                         // API tickets.show
Web TicketController@show → Inertia 'tickets/Show' with props ticket, replies, attachments, activities, agents, can
resources/js/types/tickets.ts: TicketDetail, TicketCan,
    ReplyItem { id, body, is_internal, author: UserSummary, attachments: AttachmentItem[], created_at: string | null },
    AttachmentItem { id, original_name, mime_type, size_bytes, is_internal, download_url, created_at: string | null }
// T04 tests/Support
Tests\Support\AuthorizationMatrix::for($this)->actingAsRole(Role)/asGuest()/expectAccess(string $method, string $uri, int $status, array $payload = [])

// config (see Contract notes: keys assumed)
config('attachments.disk')        // string
config('attachments.extensions')  // list<string>: pdf,png,jpg,jpeg,gif,txt,csv,doc,docx,xls,xlsx,zip
config('attachments.max_kb') // int: 10240
config('attachments.max_files')   // int: 5
config('tickets.per_page.default') / config('tickets.per_page.max')   // 20 / 100 (T05)
```

### Produces (README §3, exactly)

```php
// §3.5
App\Actions\Conversation\AddReply::handle(User $author, Ticket $ticket, string $body, bool $isInternal, array $files = []): TicketReply // throws TicketClosed

// §3.12
App\Http\Resources\V1\TicketReplyResource   // id, body, is_internal, author{id,name}, attachments[], created_at

// §3.10 — web (routes/web/conversation.php)
POST /tickets/{ticket}/replies          tickets.replies.store
GET  /attachments/{attachment}          attachments.download
// §3.10 — API (routes/api/v1/conversation.php, names prefixed api.v1.)
GET  /api/v1/tickets/{ticket}/replies   api.v1.tickets.replies.index
POST /api/v1/tickets/{ticket}/replies   api.v1.tickets.replies.store
GET  /api/v1/attachments/{attachment}   api.v1.attachments.download

// §3.13 — component replacements, props unchanged
components/tickets/ConversationPanel.vue  props { ticket: TicketDetail; replies: ReplyItem[]; can: TicketCan }
components/tickets/AttachmentList.vue     props { attachments: AttachmentItem[] }

// §3.7
ResumeSlaOnCustomerReply: no listener class. AddReply calls ChangeTicketStatus(null, $ticket, InProgress)
inside its transaction when the ticket's customer posts a public reply on a WaitingForCustomer ticket.
ChangeTicketStatus performs the SLA resume (TD-042) and fires TicketStatusChanged.
```

Task-internal (not crossing a task boundary): `StoreReplyRequest`, `IndexRepliesRequest`, `ConversationQuery::paginateFor(Ticket, User, int): LengthAwarePaginator`, the four controllers, `resources/js/lib/attachments.ts`.

**Request fields** (`StoreReplyRequest`, web multipart and API multipart/JSON): `body` (required string ≤ 20000), `is_internal` (optional boolean), `attachments` (optional array ≤ `max_files`), `attachments.*` (`File::types(extensions)->extensions(extensions)->max(max_kb)`).

## 4. Subtasks & mini-waves

| Subtask | Files | Depends on | Parallel-safe with |
|---|---|---|---|
| T07.1 AddReply action | `app/Actions/Conversation/AddReply.php`, `tests/Feature/Conversation/AddReplyTest.php` | — | T07.5 |
| T07.2 Reply endpoints + resource | `StoreReplyRequest`, `IndexRepliesRequest`, `ConversationQuery`, `Web/TicketReplyController`, `Api/V1/TicketReplyController`, `TicketReplyResource`, `routes/web/conversation.php`, `routes/api/v1/conversation.php`, `tests/Feature/Conversation/ReplyEndpointsTest.php` | T07.1 | T07.5 |
| T07.3 Attachment download | `Web/AttachmentDownloadController`, `Api/V1/AttachmentDownloadController`, both route files (append), `tests/Feature/Conversation/AttachmentDownloadTest.php` | T07.2 (route files) | T07.5 |
| T07.4 Leakage + authorization matrix | `tests/Feature/Conversation/ReplyVisibilityTest.php`, `tests/Feature/Authorization/ConversationAuthorizationTest.php` | T07.2, T07.3 | T07.5 |
| T07.5 Vue components | `resources/js/lib/attachments.ts`, `ConversationPanel.vue`, `AttachmentList.vue` | — (uses fixed URL paths) | T07.1–T07.4 |

Mini-waves: **A** = T07.1 ∥ T07.5 → **B** = T07.2 → **C** = T07.3 → **D** = T07.4. T07.5 touches only `resources/js`, so a second subagent may run it at any time.

---

## 5. Subtasks

### T07.1 — AddReply action

- [ ] **Step 1: Write the failing test** `tests/Feature/Conversation/AddReplyTest.php`

```php
<?php

declare(strict_types=1);

use App\Actions\Conversation\AddReply;
use App\Actions\Tickets\ChangeTicketStatus;
use App\Enums\TicketStatus;
use App\Events\TicketReplied;
use App\Exceptions\Domain\TicketClosed;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake(config('attachments.disk'));

    $this->customer = User::factory()->customer()->create();
    $this->agent = User::factory()->agent()->create();
    $this->ticket = Ticket::factory()
        ->status(TicketStatus::InProgress)
        ->assignedTo($this->agent)
        ->create([
            'customer_id' => $this->customer->id,
            'created_by_id' => $this->customer->id,
        ]);
});

it('stores a public staff reply', function (): void {
    $reply = app(AddReply::class)->handle($this->agent, $this->ticket, 'We are on it.', false);

    expect($reply->exists)->toBeTrue()
        ->and($reply->is_internal)->toBeFalse()
        ->and($reply->ticket_id)->toBe($this->ticket->id)
        ->and($reply->author_id)->toBe($this->agent->id)
        ->and($reply->body)->toBe('We are on it.');
});

it('lets staff write an internal note whose attachments are internal too', function (): void {
    $reply = app(AddReply::class)->handle(
        $this->agent,
        $this->ticket,
        'Customer is on the legacy plan.',
        true,
        [UploadedFile::fake()->create('notes.pdf', 12, 'application/pdf')],
    );

    expect($reply->is_internal)->toBeTrue();
    expect(Attachment::query()->where('reply_id', $reply->id)->sole()->is_internal)->toBeTrue();
});

it('forces is_internal to false when a customer asks for an internal note', function (): void {
    $reply = app(AddReply::class)->handle($this->customer, $this->ticket, 'Let me sneak a note in.', true);

    expect($reply->is_internal)->toBeFalse();
    expect(TicketReply::query()->where('is_internal', true)->exists())->toBeFalse();
});

it('rejects replies on a closed ticket', function (User $author): void {
    $closed = Ticket::factory()->status(TicketStatus::Closed)->create([
        'customer_id' => $this->customer->id,
        'created_by_id' => $this->customer->id,
    ]);

    expect(fn () => app(AddReply::class)->handle($author, $closed, 'Hello?', false))
        ->toThrow(TicketClosed::class);
    expect(TicketReply::query()->where('ticket_id', $closed->id)->exists())->toBeFalse();
})->with([
    'customer' => [fn (): User => $this->customer],
    'agent' => [fn (): User => $this->agent],
]);

it('stores the reply and its attachments together', function (): void {
    $reply = app(AddReply::class)->handle($this->agent, $this->ticket, 'See attached.', false, [
        UploadedFile::fake()->create('invoice.pdf', 20, 'application/pdf'),
        UploadedFile::fake()->image('screenshot.png'),
    ]);

    $attachments = Attachment::query()->where('reply_id', $reply->id)->get();

    expect($attachments)->toHaveCount(2);
    $attachments->each(function (Attachment $attachment): void {
        expect($attachment->ticket_id)->toBe($this->ticket->id)
            ->and($attachment->is_internal)->toBeFalse();
        Storage::disk($attachment->disk)->assertExists($attachment->path);
    });
});

it('deletes the stored files when the transaction fails after storing them', function (): void {
    // Registered after the fixture is created, so only AddReply's touch() of the ticket trips it.
    Ticket::updating(function (): void {
        throw new RuntimeException('simulated failure');
    });

    expect(fn () => app(AddReply::class)->handle($this->agent, $this->ticket, 'Will roll back.', false, [
        UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
        UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'),
    ]))->toThrow(RuntimeException::class, 'simulated failure');

    expect(Storage::disk(config('attachments.disk'))->allFiles())->toBeEmpty()
        ->and(TicketReply::query()->count())->toBe(0)
        ->and(Attachment::query()->count())->toBe(0);
});

it('moves a WaitingForCustomer ticket to InProgress on a customer reply and resumes the SLA', function (): void {
    // Sunday 2026-11-01 09:00 Asia/Dhaka (03:00 UTC) start, due 17:00 Dhaka (11:00 UTC).
    $ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($this->agent)->create([
        'customer_id' => $this->customer->id,
        'created_by_id' => $this->customer->id,
        'sla_started_at' => CarbonImmutable::parse('2026-11-01 03:00:00', 'UTC'),
        'sla_warning_at' => CarbonImmutable::parse('2026-11-01 09:00:00', 'UTC'),
        'sla_due_at' => CarbonImmutable::parse('2026-11-01 11:00:00', 'UTC'),
        'sla_paused_at' => null,
    ]);

    // Paused Sunday 10:00 Dhaka: 420 business minutes remain.
    $this->travelTo(CarbonImmutable::parse('2026-11-01 04:00:00', 'UTC'));
    $ticket = app(ChangeTicketStatus::class)->handle($this->agent, $ticket, TicketStatus::WaitingForCustomer);
    expect($ticket->fresh()->sla_paused_at)->not->toBeNull();

    // Customer answers Monday 10:00 Dhaka: due moves to Monday 17:00 Dhaka (11:00 UTC).
    $this->travelTo(CarbonImmutable::parse('2026-11-02 04:00:00', 'UTC'));
    app(AddReply::class)->handle($this->customer, $ticket->fresh(), 'Here is the info you asked for.', false);

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::InProgress)
        ->and($ticket->sla_paused_at)->toBeNull()
        ->and($ticket->sla_due_at->utc()->toDateTimeString())->toBe('2026-11-02 11:00:00');
});

it('keeps WaitingForCustomer when staff reply', function (): void {
    $ticket = app(ChangeTicketStatus::class)->handle($this->agent, $this->ticket, TicketStatus::WaitingForCustomer);

    app(AddReply::class)->handle($this->agent, $ticket->fresh(), 'Any update?', false);

    expect($ticket->fresh()->status)->toBe(TicketStatus::WaitingForCustomer);
});

it('fires TicketReplied with the ticket, reply and author', function (): void {
    Event::fake([TicketReplied::class]);

    $reply = app(AddReply::class)->handle($this->agent, $this->ticket, 'Done.', false);

    Event::assertDispatched(TicketReplied::class, fn (TicketReplied $event): bool => $event->reply->is($reply)
        && $event->ticket->is($this->ticket)
        && $event->author->is($this->agent));
});
```

- [ ] **Step 2: Run it, expect FAIL**

```bash
./vendor/bin/pest tests/Feature/Conversation/AddReplyTest.php
```
Expected: FAIL, every test errors with `Error: Class "App\Actions\Conversation\AddReply" not found`.

- [ ] **Step 3: Implement** `app/Actions/Conversation/AddReply.php`

```php
<?php

declare(strict_types=1);

namespace App\Actions\Conversation;

use App\Actions\Tickets\ChangeTicketStatus;
use App\Enums\TicketStatus;
use App\Events\TicketReplied;
use App\Exceptions\Domain\TicketClosed;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Services\Attachments\AttachmentStorer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AddReply
{
    public function __construct(
        private readonly AttachmentStorer $storer,
        private readonly ChangeTicketStatus $changeTicketStatus,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     *
     * @throws TicketClosed
     */
    public function handle(User $author, Ticket $ticket, string $body, bool $isInternal, array $files = []): TicketReply
    {
        // Customers can never author internal notes, whatever the request says (TD-060).
        $isInternal = $isInternal && $author->can('addInternalNote', $ticket);

        /** @var Collection<int, Attachment> $stored */
        $stored = new Collection;

        try {
            return DB::transaction(function () use ($author, $ticket, $body, $isInternal, $files, &$stored): TicketReply {
                // Lock so a concurrent close cannot slip in between the check and the insert.
                $current = Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();

                if ($current->status === TicketStatus::Closed) {
                    throw new TicketClosed(sprintf('Ticket %s is closed and no longer accepts replies.', $current->number));
                }

                $reply = new TicketReply(['body' => $body, 'is_internal' => $isInternal]);
                $reply->ticket()->associate($current);
                $reply->author()->associate($author);
                $reply->save();

                // One file per call, so files stored before a mid-batch failure are known and compensated.
                foreach ($files as $file) {
                    $stored = $stored->merge($this->storer->storeMany($current, $reply, $author, [$file], $isInternal));
                }

                if (! $isInternal
                    && $author->getKey() === $current->customer_id
                    && $current->status === TicketStatus::WaitingForCustomer) {
                    // System actor: the customer did not choose the transition, their answer caused it (TD-042).
                    $current = $this->changeTicketStatus->handle(null, $current, TicketStatus::InProgress);
                }

                // Keeps "recently updated" sorting meaningful for conversation activity.
                $current->touch();

                TicketReplied::dispatch($current, $reply, $author);

                return $reply;
            });
        } catch (Throwable $exception) {
            // Files are not transactional: remove what was written before the rollback (TD-053).
            $this->storer->deleteFiles($stored);

            throw $exception;
        }
    }
}
```

- [ ] **Step 4: Run it, expect PASS**

```bash
./vendor/bin/pest tests/Feature/Conversation/AddReplyTest.php
```
Expected: PASS, 10 tests (the closed-ticket test runs twice from its dataset).

- [ ] **Step 5: Style and static analysis**

```bash
./vendor/bin/pint app/Actions/Conversation tests/Feature/Conversation/AddReplyTest.php && ./vendor/bin/phpstan analyse --memory-limit=1G app/Actions/Conversation
```
Expected: Pint reports the files fixed or already clean; PHPStan `[OK] No errors`.

- [ ] **Step 6: Commit**

```bash
git add app/Actions/Conversation/AddReply.php tests/Feature/Conversation/AddReplyTest.php
git commit -m "feat(conversation): add replies with SLA resume on customer reply" --trailer "Refs: T07.1"
```

---

### T07.2 — Reply endpoints, resource, replies index

- [ ] **Step 1: Write the failing test** `tests/Feature/Conversation/ReplyEndpointsTest.php`

```php
<?php

declare(strict_types=1);

use App\Actions\Conversation\AddReply;
use App\Enums\TicketStatus;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    Storage::fake(config('attachments.disk'));

    $this->customer = User::factory()->customer()->create();
    $this->agent = User::factory()->agent()->create();
    $this->ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($this->agent)->create([
        'customer_id' => $this->customer->id,
        'created_by_id' => $this->customer->id,
    ]);
});

it('stores a web reply and redirects to the ticket', function (): void {
    $this->actingAs($this->agent)
        ->from(route('tickets.show', $this->ticket))
        ->post(route('tickets.replies.store', $this->ticket), ['body' => 'Hello from the web.'])
        ->assertRedirect(route('tickets.show', $this->ticket))
        ->assertSessionHas('success');

    expect(TicketReply::query()->where('ticket_id', $this->ticket->id)->sole()->body)->toBe('Hello from the web.');
});

it('stores an API reply and returns the resource', function (): void {
    Sanctum::actingAs($this->agent, ['*']);

    $this->postJson(route('api.v1.tickets.replies.store', $this->ticket), [
        'body' => 'Hello from the API.',
        'is_internal' => true,
        'attachments' => [UploadedFile::fake()->create('trace.txt', 2, 'text/plain')],
    ])
        ->assertCreated()
        ->assertJsonPath('data.body', 'Hello from the API.')
        ->assertJsonPath('data.is_internal', true)
        ->assertJsonPath('data.author.id', $this->agent->id)
        ->assertJsonCount(1, 'data.attachments');
});

it('stores a customer reply as public even when is_internal=1 is posted', function (): void {
    $this->actingAs($this->customer)
        ->post(route('tickets.replies.store', $this->ticket), ['body' => 'Sneaky', 'is_internal' => '1'])
        ->assertRedirect();

    Sanctum::actingAs($this->customer, ['*']);
    $this->postJson(route('api.v1.tickets.replies.store', $this->ticket), ['body' => 'Sneaky API', 'is_internal' => true])
        ->assertCreated()
        ->assertJsonPath('data.is_internal', false);

    expect(TicketReply::query()->where('ticket_id', $this->ticket->id)->where('is_internal', true)->exists())->toBeFalse()
        ->and(TicketReply::query()->where('ticket_id', $this->ticket->id)->count())->toBe(2);
});

it('refuses a reply on a closed ticket at the policy (403), web and API', function (): void {
    $closed = Ticket::factory()->status(TicketStatus::Closed)->create([
        'customer_id' => $this->customer->id,
        'created_by_id' => $this->customer->id,
    ]);

    // T04: TicketPolicy::reply denies Closed tickets for everyone, so HTTP never reaches AddReply.
    Sanctum::actingAs($this->agent, ['*']);
    $this->postJson(route('api.v1.tickets.replies.store', $closed), ['body' => 'Too late'])->assertForbidden();

    $this->actingAs($this->customer)
        ->post(route('tickets.replies.store', $closed), ['body' => 'Too late'])
        ->assertForbidden();

    expect(TicketReply::query()->where('ticket_id', $closed->id)->exists())->toBeFalse();
});

it('returns 422 ticket_closed when the ticket closes between authorization and the write', function (): void {
    // AddReply is resolved after the FormRequest has authorized, so this simulates a concurrent close.
    app()->resolving(AddReply::class, function (): void {
        DB::table('tickets')->where('id', $this->ticket->id)->update(['status' => TicketStatus::Closed->value]);
    });

    Sanctum::actingAs($this->agent, ['*']);
    $this->postJson(route('api.v1.tickets.replies.store', $this->ticket), ['body' => 'Racing the close'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'ticket_closed');

    expect(TicketReply::query()->where('ticket_id', $this->ticket->id)->exists())->toBeFalse();
});

it('redirects back with an error flash when the web reply loses that race', function (): void {
    app()->resolving(AddReply::class, function (): void {
        DB::table('tickets')->where('id', $this->ticket->id)->update(['status' => TicketStatus::Closed->value]);
    });

    $this->actingAs($this->agent)
        ->from(route('tickets.show', $this->ticket))
        ->post(route('tickets.replies.store', $this->ticket), ['body' => 'Racing the close'])
        ->assertRedirect(route('tickets.show', $this->ticket))
        ->assertSessionHas('error');
});

it('rejects disallowed or oversized files', function (UploadedFile $file): void {
    Sanctum::actingAs($this->agent, ['*']);

    $this->postJson(route('api.v1.tickets.replies.store', $this->ticket), [
        'body' => 'With a bad file',
        'attachments' => [$file],
    ])->assertUnprocessable()->assertJsonValidationErrors('attachments.0');

    expect(TicketReply::query()->count())->toBe(0);
    expect(Storage::disk(config('attachments.disk'))->allFiles())->toBeEmpty();
})->with([
    'windows executable' => [fn (): UploadedFile => UploadedFile::fake()->create('tool.exe', 10, 'application/x-msdownload')],
    'php script' => [fn (): UploadedFile => UploadedFile::fake()->create('shell.php', 1, 'application/x-php')],
    'executable content behind a .png name' => [fn (): UploadedFile => UploadedFile::fake()->create('photo.png', 10, 'application/x-msdownload')],
    'pdf content behind an .exe name' => [fn (): UploadedFile => UploadedFile::fake()->create('invoice.exe', 10, 'application/pdf')],
    'one kilobyte over the limit' => [fn (): UploadedFile => UploadedFile::fake()->create('big.pdf', (int) config('attachments.max_kb') + 1, 'application/pdf')],
]);

it('rejects more files than allowed per reply', function (): void {
    Sanctum::actingAs($this->agent, ['*']);
    $files = [];
    for ($i = 0; $i <= (int) config('attachments.max_files'); $i++) {
        $files[] = UploadedFile::fake()->create("f{$i}.pdf", 1, 'application/pdf');
    }

    $this->postJson(route('api.v1.tickets.replies.store', $this->ticket), ['body' => 'Too many', 'attachments' => $files])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('attachments');
});

it('accepts every allowed type at the size limit', function (UploadedFile $file): void {
    Sanctum::actingAs($this->agent, ['*']);

    $this->postJson(route('api.v1.tickets.replies.store', $this->ticket), ['body' => 'Fine', 'attachments' => [$file]])
        ->assertCreated();
})->with([
    'pdf at max size' => [fn (): UploadedFile => UploadedFile::fake()->create('max.pdf', (int) config('attachments.max_kb'), 'application/pdf')],
    'png' => [fn (): UploadedFile => UploadedFile::fake()->image('shot.png')],
    'jpg' => [fn (): UploadedFile => UploadedFile::fake()->image('shot.jpg')],
    'txt' => [fn (): UploadedFile => UploadedFile::fake()->create('log.txt', 3, 'text/plain')],
    'csv' => [fn (): UploadedFile => UploadedFile::fake()->create('rows.csv', 3, 'text/csv')],
    'docx' => [fn (): UploadedFile => UploadedFile::fake()->create('spec.docx', 3, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')],
    'xlsx' => [fn (): UploadedFile => UploadedFile::fake()->create('sheet.xlsx', 3, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')],
    'zip' => [fn (): UploadedFile => UploadedFile::fake()->create('bundle.zip', 3, 'application/zip')],
]);

it('stores attachments under a UUID name and keeps the original name only as metadata', function (): void {
    Sanctum::actingAs($this->agent, ['*']);

    $this->postJson(route('api.v1.tickets.replies.store', $this->ticket), [
        'body' => 'Report attached',
        'attachments' => [UploadedFile::fake()->create('Quarterly Report.pdf', 5, 'application/pdf')],
    ])->assertCreated();

    $attachment = Attachment::query()->sole();

    expect($attachment->path)
        ->toMatch('#^attachments/'.$this->ticket->id.'/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.pdf$#')
        ->not->toContain('Quarterly')
        ->and($attachment->original_name)->toBe('Quarterly Report.pdf');
    Storage::disk($attachment->disk)->assertExists($attachment->path);
});

it('lists replies oldest first with pagination meta', function (): void {
    TicketReply::factory()->for($this->ticket)->for($this->agent, 'author')->create(['body' => 'first', 'created_at' => now()->subHour()]);
    TicketReply::factory()->for($this->ticket)->for($this->customer, 'author')->create(['body' => 'second', 'created_at' => now()]);

    Sanctum::actingAs($this->agent, ['*']);
    $this->getJson(route('api.v1.tickets.replies.index', ['ticket' => $this->ticket, 'per_page' => 1]))
        ->assertOk()
        ->assertJsonPath('data.0.body', 'first')
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('meta.total', 2);
});

it('rejects per_page above 100 on the replies index', function (): void {
    Sanctum::actingAs($this->agent, ['*']);

    $this->getJson(route('api.v1.tickets.replies.index', ['ticket' => $this->ticket, 'per_page' => 101]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('per_page');
});
```

- [ ] **Step 2: Run it, expect FAIL**

```bash
./vendor/bin/pest tests/Feature/Conversation/ReplyEndpointsTest.php
```
Expected: FAIL with `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [tickets.replies.store] not defined.` (and `api.v1.tickets.replies.store` / `api.v1.tickets.replies.index` in the API tests).

- [ ] **Step 3: Implement** `app/Http/Requests/StoreReplyRequest.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\File;

final class StoreReplyRequest extends FormRequest
{
    public function authorize(): Response
    {
        // TicketPolicy::reply answers 404 for a ticket the user may not see (TD-012) and 403 on Closed (T04).
        return Gate::forUser($this->user())->inspect('reply', $this->ticket());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var list<string> $extensions */
        $extensions = config('attachments.extensions');

        return [
            'body' => ['required', 'string', 'max:20000'],
            'is_internal' => ['sometimes', 'boolean'],
            'attachments' => ['sometimes', 'array', 'max:'.(int) config('attachments.max_files')],
            // types() sniffs the content's MIME type; extensions() checks the client name. Both must pass (TD-061).
            'attachments.*' => [
                'required',
                File::types($extensions)
                    ->extensions($extensions)
                    ->max((int) config('attachments.max_kb')),
            ],
        ];
    }

    public function ticket(): Ticket
    {
        $ticket = $this->route('ticket');
        assert($ticket instanceof Ticket);

        return $ticket;
    }

    public function actor(): User
    {
        $user = $this->user();
        assert($user instanceof User);

        return $user;
    }

    public function replyBody(): string
    {
        return $this->string('body')->toString();
    }

    public function wantsInternal(): bool
    {
        return $this->boolean('is_internal');
    }

    /**
     * @return list<UploadedFile>
     */
    public function attachmentFiles(): array
    {
        return array_values(array_filter(
            Arr::wrap($this->file('attachments', [])),
            static fn (mixed $file): bool => $file instanceof UploadedFile,
        ));
    }
}
```

- [ ] **Step 4: Implement** `app/Http/Requests/IndexRepliesRequest.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class IndexRepliesRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::forUser($this->user())->inspect('view', $this->ticket());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('tickets.per_page.max', 100)],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function ticket(): Ticket
    {
        $ticket = $this->route('ticket');
        assert($ticket instanceof Ticket);

        return $ticket;
    }

    public function actor(): User
    {
        $user = $this->user();
        assert($user instanceof User);

        return $user;
    }

    public function perPage(): int
    {
        return $this->integer('per_page', (int) config('tickets.per_page.default', 20));
    }
}
```

- [ ] **Step 5: Implement** `app/Services/Conversation/ConversationQuery.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\Conversation;

use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ConversationQuery
{
    /**
     * @return LengthAwarePaginator<int, TicketReply>
     */
    public function paginateFor(Ticket $ticket, User $viewer, int $perPage): LengthAwarePaginator
    {
        $seesInternal = $viewer->can('viewInternal', $ticket);

        $paginator = $ticket->replies()
            ->visibleTo($viewer)
            ->with([
                'author:id,name',
                'attachments' => static function (HasMany $query) use ($seesInternal): void {
                    $query->select(['id', 'ticket_id', 'reply_id', 'original_name', 'mime_type', 'size_bytes', 'is_internal', 'created_at']);

                    // Defence in depth: public replies only carry public files, but never rely on that alone.
                    if (! $seesInternal) {
                        $query->where('is_internal', false);
                    }
                },
            ])
            ->oldest()
            ->oldest('id')
            ->paginate($perPage)
            ->withQueryString();

        // The resource's visibility check needs the ticket; set it instead of lazy loading it per reply.
        $paginator->getCollection()->each(static fn (TicketReply $reply): TicketReply => $reply->setRelation('ticket', $ticket));

        return $paginator;
    }
}
```

- [ ] **Step 6: Implement** `app/Http/Resources/V1/TicketReplyResource.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\TicketReply;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TicketReply
 */
final class TicketReplyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     *
     * @throws AuthorizationException
     */
    public function toArray(Request $request): array
    {
        /** @var TicketReply $reply */
        $reply = $this->resource;
        $viewer = $request->user();

        // Second leakage layer (TD-120): if a query ever forgets visibleTo, fail closed instead of serializing the note.
        if ($reply->is_internal && ($viewer === null || $viewer->cannot('view', $reply))) {
            throw new AuthorizationException('This reply is not visible to you.');
        }

        return [
            'id' => $reply->id,
            'body' => $reply->body,
            'is_internal' => $reply->is_internal,
            'author' => new UserSummaryResource($this->whenLoaded('author')),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'created_at' => $reply->created_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 7: Implement** `app/Http/Controllers/Web/TicketReplyController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Conversation\AddReply;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReplyRequest;
use Illuminate\Http\RedirectResponse;

final class TicketReplyController extends Controller
{
    public function store(StoreReplyRequest $request, AddReply $addReply): RedirectResponse
    {
        $ticket = $request->ticket();

        $reply = $addReply->handle(
            $request->actor(),
            $ticket,
            $request->replyBody(),
            $request->wantsInternal(),
            $request->attachmentFiles(),
        );

        return to_route('tickets.show', $ticket)
            ->with('success', $reply->is_internal ? __('Internal note added.') : __('Reply sent.'));
    }
}
```

- [ ] **Step 8: Implement** `app/Http/Controllers/Api/V1/TicketReplyController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Conversation\AddReply;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexRepliesRequest;
use App\Http\Requests\StoreReplyRequest;
use App\Http\Resources\V1\TicketReplyResource;
use App\Services\Conversation\ConversationQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class TicketReplyController extends Controller
{
    public function index(IndexRepliesRequest $request, ConversationQuery $conversation): AnonymousResourceCollection
    {
        return TicketReplyResource::collection(
            $conversation->paginateFor($request->ticket(), $request->actor(), $request->perPage()),
        );
    }

    public function store(StoreReplyRequest $request, AddReply $addReply): JsonResponse
    {
        $ticket = $request->ticket();

        $reply = $addReply->handle(
            $request->actor(),
            $ticket,
            $request->replyBody(),
            $request->wantsInternal(),
            $request->attachmentFiles(),
        );

        $reply->load([
            'author:id,name',
            'attachments:id,ticket_id,reply_id,original_name,mime_type,size_bytes,is_internal,created_at',
        ]);
        $reply->setRelation('ticket', $ticket);

        return TicketReplyResource::make($reply)->response()->setStatusCode(201);
    }
}
```

- [ ] **Step 9: Create** `routes/web/conversation.php`

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\Web\TicketReplyController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    // Upload throttle, spec §17.
    Route::post('tickets/{ticket}/replies', [TicketReplyController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('tickets.replies.store');
});
```

- [ ] **Step 10: Create** `routes/api/v1/conversation.php`

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\TicketReplyController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('tickets/{ticket}/replies', [TicketReplyController::class, 'index'])
        ->name('tickets.replies.index');

    Route::post('tickets/{ticket}/replies', [TicketReplyController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('tickets.replies.store');
});
```

- [ ] **Step 11: Run it, expect PASS**

```bash
./vendor/bin/pest tests/Feature/Conversation/ReplyEndpointsTest.php
```
Expected: PASS, 23 tests (13 of them from the two datasets).

- [ ] **Step 12: Confirm the routes**

```bash
php artisan route:list --name=replies
```
Expected: three rows, `tickets.replies.store` (POST `tickets/{ticket}/replies`), `api.v1.tickets.replies.index` (GET `api/v1/tickets/{ticket}/replies`) and `api.v1.tickets.replies.store` (POST).

- [ ] **Step 13: Style and static analysis**

```bash
./vendor/bin/pint app/Http routes/web/conversation.php routes/api/v1/conversation.php app/Services/Conversation tests/Feature/Conversation && ./vendor/bin/phpstan analyse --memory-limit=1G
```
Expected: Pint clean; PHPStan `[OK] No errors`.

- [ ] **Step 14: Commit**

```bash
git add app/Http/Requests/StoreReplyRequest.php app/Http/Requests/IndexRepliesRequest.php \
  app/Services/Conversation/ConversationQuery.php app/Http/Resources/V1/TicketReplyResource.php \
  app/Http/Controllers/Web/TicketReplyController.php app/Http/Controllers/Api/V1/TicketReplyController.php \
  routes/web/conversation.php routes/api/v1/conversation.php tests/Feature/Conversation/ReplyEndpointsTest.php
git commit -m "feat(conversation): add reply endpoints and reply resource" --trailer "Refs: T07.2"
```

---

### T07.3 — Authorized streaming download

- [ ] **Step 1: Write the failing test** `tests/Feature/Conversation/AttachmentDownloadTest.php`

```php
<?php

declare(strict_types=1);

use App\Actions\Conversation\AddReply;
use App\Enums\TicketStatus;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    Storage::fake(config('attachments.disk'));

    $this->customer = User::factory()->customer()->create();
    $this->agent = User::factory()->agent()->create();
    $this->ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($this->agent)->create([
        'customer_id' => $this->customer->id,
        'created_by_id' => $this->customer->id,
    ]);

    $public = app(AddReply::class)->handle($this->agent, $this->ticket, 'Public', false, [
        UploadedFile::fake()->create('report.pdf', 4, 'application/pdf'),
    ]);
    $internal = app(AddReply::class)->handle($this->agent, $this->ticket, 'Internal', true, [
        UploadedFile::fake()->create('internal-notes.pdf', 4, 'application/pdf'),
    ]);

    $this->publicAttachment = Attachment::query()->where('reply_id', $public->id)->sole();
    $this->internalAttachment = Attachment::query()->where('reply_id', $internal->id)->sole();
});

it('streams a public attachment to the ticket owner with safe headers (web)', function (): void {
    $response = $this->actingAs($this->customer)->get(route('attachments.download', $this->publicAttachment));

    $response->assertOk()
        ->assertDownload('report.pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment');
});

it('streams a public attachment to the ticket owner with safe headers (API)', function (): void {
    Sanctum::actingAs($this->customer, ['*']);

    $response = $this->get(route('api.v1.attachments.download', $this->publicAttachment));

    $response->assertOk()
        ->assertDownload('report.pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('lets staff download an internal attachment', function (): void {
    $this->actingAs($this->agent)
        ->get(route('attachments.download', $this->internalAttachment))
        ->assertOk()
        ->assertDownload('internal-notes.pdf');
});

it('never lets the customer download an internal attachment', function (): void {
    // AttachmentPolicy hides internal files as not found (T04).
    $this->actingAs($this->customer)->get(route('attachments.download', $this->internalAttachment))->assertNotFound();

    Sanctum::actingAs($this->customer, ['*']);
    $this->getJson(route('api.v1.attachments.download', $this->internalAttachment))->assertNotFound();
});

it('never lets another customer download any attachment of the ticket', function (): void {
    $stranger = User::factory()->customer()->create();

    $this->actingAs($stranger)->get(route('attachments.download', $this->publicAttachment))->assertNotFound();
});

it('requires authentication', function (): void {
    $this->get(route('attachments.download', $this->publicAttachment))->assertRedirect(route('login'));
    $this->getJson(route('api.v1.attachments.download', $this->publicAttachment))->assertUnauthorized();
});
```

- [ ] **Step 2: Run it, expect FAIL**

```bash
./vendor/bin/pest tests/Feature/Conversation/AttachmentDownloadTest.php
```
Expected: FAIL with `RouteNotFoundException: Route [attachments.download] not defined.`

- [ ] **Step 3: Implement** `app/Http/Controllers/Web/AttachmentDownloadController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AttachmentDownloadController extends Controller
{
    public function __invoke(Attachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $attachment);

        // attachment disposition + nosniff: the browser never renders user-supplied content inline (TD-061).
        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
```

- [ ] **Step 4: Implement** `app/Http/Controllers/Api/V1/AttachmentDownloadController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AttachmentDownloadController extends Controller
{
    public function __invoke(Attachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $attachment);

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
```

- [ ] **Step 5: Replace** `routes/web/conversation.php` with

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\Web\AttachmentDownloadController;
use App\Http\Controllers\Web\TicketReplyController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    // Upload throttle, spec §17.
    Route::post('tickets/{ticket}/replies', [TicketReplyController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('tickets.replies.store');

    Route::get('attachments/{attachment}', AttachmentDownloadController::class)
        ->name('attachments.download');
});
```

- [ ] **Step 6: Replace** `routes/api/v1/conversation.php` with

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AttachmentDownloadController;
use App\Http\Controllers\Api\V1\TicketReplyController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('tickets/{ticket}/replies', [TicketReplyController::class, 'index'])
        ->name('tickets.replies.index');

    Route::post('tickets/{ticket}/replies', [TicketReplyController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('tickets.replies.store');

    Route::get('attachments/{attachment}', AttachmentDownloadController::class)
        ->name('attachments.download');
});
```

- [ ] **Step 7: Run it, expect PASS**

```bash
./vendor/bin/pest tests/Feature/Conversation/AttachmentDownloadTest.php tests/Feature/Conversation/ReplyEndpointsTest.php
```
Expected: PASS, 29 tests.

- [ ] **Step 8: Style and static analysis**

```bash
./vendor/bin/pint app/Http/Controllers routes tests/Feature/Conversation && ./vendor/bin/phpstan analyse --memory-limit=1G
```
Expected: Pint clean; PHPStan `[OK] No errors`.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/Web/AttachmentDownloadController.php app/Http/Controllers/Api/V1/AttachmentDownloadController.php \
  routes/web/conversation.php routes/api/v1/conversation.php tests/Feature/Conversation/AttachmentDownloadTest.php
git commit -m "feat(attachments): stream attachment downloads behind policy check" --trailer "Refs: T07.3"
```

---

### T07.4 — Leakage tests and the authorization matrix

These tests guard code from T05 (`show` props, `TicketResource`) as well as T07. If one fails because of T05 code, **stop and report**; don't edit T05's files.

- [ ] **Step 1: Write the test** `tests/Feature/Conversation/ReplyVisibilityTest.php`

```php
<?php

declare(strict_types=1);

use App\Actions\Conversation\AddReply;
use App\Enums\TicketStatus;
use App\Http\Resources\V1\TicketReplyResource;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    Storage::fake(config('attachments.disk'));

    $this->customer = User::factory()->customer()->create();
    $this->agent = User::factory()->agent()->create();
    $this->ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($this->agent)->create([
        'customer_id' => $this->customer->id,
        'created_by_id' => $this->customer->id,
    ]);

    app(AddReply::class)->handle($this->agent, $this->ticket, 'PUBLIC-ANSWER', false);
    $this->internal = app(AddReply::class)->handle($this->agent, $this->ticket, 'SECRET-INTERNAL-NOTE', true, [
        UploadedFile::fake()->create('secret-file.pdf', 2, 'application/pdf'),
    ]);
});

it('never puts internal replies in the web props for a customer', function (): void {
    $this->actingAs($this->customer)
        ->get(route('tickets.show', $this->ticket))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tickets/Show')
            ->has('replies', 1)
            ->where('replies.0.body', 'PUBLIC-ANSWER')
            ->where('replies.0.is_internal', false))
        ->assertDontSee('SECRET-INTERNAL-NOTE')
        ->assertDontSee('secret-file.pdf');
});

it('shows internal replies in the web props for staff', function (): void {
    $this->actingAs($this->agent)
        ->get(route('tickets.show', $this->ticket))
        ->assertInertia(fn (Assert $page) => $page->has('replies', 2));
});

it('never returns internal replies from the API replies index for a customer', function (): void {
    Sanctum::actingAs($this->customer, ['*']);

    $this->getJson(route('api.v1.tickets.replies.index', $this->ticket))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.body', 'PUBLIC-ANSWER')
        ->assertDontSee('SECRET-INTERNAL-NOTE')
        ->assertDontSee('secret-file.pdf');
});

it('returns internal replies from the API replies index for staff', function (): void {
    Sanctum::actingAs($this->agent, ['*']);

    $this->getJson(route('api.v1.tickets.replies.index', $this->ticket))
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('never leaks internal content through the API ticket show resource', function (): void {
    Sanctum::actingAs($this->customer, ['*']);

    $this->getJson(route('api.v1.tickets.show', $this->ticket))
        ->assertOk()
        ->assertDontSee('SECRET-INTERNAL-NOTE')
        ->assertDontSee('secret-file.pdf');
});

it('refuses to serialize an internal reply for a customer even when a query forgets visibleTo', function (): void {
    $request = Request::create('/');
    $request->setUserResolver(fn (): User => $this->customer);

    $this->internal->setRelation('ticket', $this->ticket);

    expect(fn () => TicketReplyResource::make($this->internal)->toArray($request))
        ->toThrow(AuthorizationException::class);
});
```

- [ ] **Step 2: Run it**

```bash
./vendor/bin/pest tests/Feature/Conversation/ReplyVisibilityTest.php
```
Expected: PASS, 6 tests. (The production code already exists from T05 and T07.2. If a test fails, it has found a real leak: stop and report which layer failed.)

- [ ] **Step 3: Write the matrix** `tests/Feature/Authorization/ConversationAuthorizationTest.php` (T04's `AuthorizationMatrix` helper: `/api/` URIs go out as JSON with Sanctum, others use the web session)

```php
<?php

declare(strict_types=1);

use App\Actions\Conversation\AddReply;
use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AuthorizationMatrix;

beforeEach(function (): void {
    Storage::fake(config('attachments.disk'));
});

/*
 * $relation: 'own'   → the acting customer owns the ticket
 *            'other' → the ticket belongs to a different customer (for staff this is the normal case)
 * $role null → guest.
 */
it('enforces the conversation matrix', function (?Role $role, string $relation, string $method, string $target, string $ticketStatus, int $status): void {
    $matrix = AuthorizationMatrix::for($this);
    $actor = $role === null ? null : $matrix->actingAsRole($role);
    if ($actor === null) {
        $matrix->asGuest();
    }

    $owner = $relation === 'own' && $actor !== null ? $actor : User::factory()->customer()->create();
    $writer = User::factory()->agent()->create();
    $ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($writer)->create([
        'customer_id' => $owner->id,
        'created_by_id' => $owner->id,
    ]);

    $public = app(AddReply::class)->handle($writer, $ticket, 'Public', false, [UploadedFile::fake()->create('public.pdf', 2, 'application/pdf')]);
    $internal = app(AddReply::class)->handle($writer, $ticket, 'Internal', true, [UploadedFile::fake()->create('internal.pdf', 2, 'application/pdf')]);
    $publicFile = Attachment::query()->where('reply_id', $public->id)->sole();
    $internalFile = Attachment::query()->where('reply_id', $internal->id)->sole();

    if ($ticketStatus === 'closed') {
        // Fixture only: the replies above were written while the ticket was open.
        $ticket->forceFill(['status' => TicketStatus::Closed, 'closed_at' => now()])->save();
    }

    $uri = match ($target) {
        'web.reply' => route('tickets.replies.store', $ticket),
        'api.reply' => route('api.v1.tickets.replies.store', $ticket),
        'api.replies' => route('api.v1.tickets.replies.index', $ticket),
        'web.public_file' => route('attachments.download', $publicFile),
        'api.public_file' => route('api.v1.attachments.download', $publicFile),
        'web.internal_file' => route('attachments.download', $internalFile),
        'api.internal_file' => route('api.v1.attachments.download', $internalFile),
    };

    $matrix->expectAccess($method, $uri, $status, $method === 'POST' ? ['body' => 'matrix reply'] : []);
})->with([
    // web reply
    'guest · web reply' => [null, 'other', 'POST', 'web.reply', 'open', 302],
    'customer · own · web reply' => [Role::Customer, 'own', 'POST', 'web.reply', 'open', 302],
    'customer · other · web reply' => [Role::Customer, 'other', 'POST', 'web.reply', 'open', 404],
    'agent · web reply' => [Role::Agent, 'other', 'POST', 'web.reply', 'open', 302],
    'admin · web reply' => [Role::Admin, 'other', 'POST', 'web.reply', 'open', 302],
    // API reply
    'guest · api reply' => [null, 'other', 'POST', 'api.reply', 'open', 401],
    'customer · own · api reply' => [Role::Customer, 'own', 'POST', 'api.reply', 'open', 201],
    'customer · other · api reply' => [Role::Customer, 'other', 'POST', 'api.reply', 'open', 404],
    'agent · api reply' => [Role::Agent, 'other', 'POST', 'api.reply', 'open', 201],
    'admin · api reply' => [Role::Admin, 'other', 'POST', 'api.reply', 'open', 201],
    // reply on a Closed ticket: T04 denies for everyone
    'customer · own · api reply · closed' => [Role::Customer, 'own', 'POST', 'api.reply', 'closed', 403],
    'agent · api reply · closed' => [Role::Agent, 'other', 'POST', 'api.reply', 'closed', 403],
    'admin · web reply · closed' => [Role::Admin, 'other', 'POST', 'web.reply', 'closed', 403],
    // API replies index
    'guest · api replies' => [null, 'other', 'GET', 'api.replies', 'open', 401],
    'customer · own · api replies' => [Role::Customer, 'own', 'GET', 'api.replies', 'open', 200],
    'customer · other · api replies' => [Role::Customer, 'other', 'GET', 'api.replies', 'open', 404],
    'agent · api replies' => [Role::Agent, 'other', 'GET', 'api.replies', 'open', 200],
    'admin · api replies' => [Role::Admin, 'other', 'GET', 'api.replies', 'open', 200],
    // public attachment
    'guest · web public file' => [null, 'other', 'GET', 'web.public_file', 'open', 302],
    'customer · own · web public file' => [Role::Customer, 'own', 'GET', 'web.public_file', 'open', 200],
    'customer · other · web public file' => [Role::Customer, 'other', 'GET', 'web.public_file', 'open', 404],
    'agent · web public file' => [Role::Agent, 'other', 'GET', 'web.public_file', 'open', 200],
    'admin · web public file' => [Role::Admin, 'other', 'GET', 'web.public_file', 'open', 200],
    'guest · api public file' => [null, 'other', 'GET', 'api.public_file', 'open', 401],
    'customer · own · api public file' => [Role::Customer, 'own', 'GET', 'api.public_file', 'open', 200],
    'customer · other · api public file' => [Role::Customer, 'other', 'GET', 'api.public_file', 'open', 404],
    'agent · api public file' => [Role::Agent, 'other', 'GET', 'api.public_file', 'open', 200],
    // internal attachment: never a customer, hidden as 404 (T04)
    'customer · own · web internal file' => [Role::Customer, 'own', 'GET', 'web.internal_file', 'open', 404],
    'customer · other · web internal file' => [Role::Customer, 'other', 'GET', 'web.internal_file', 'open', 404],
    'agent · web internal file' => [Role::Agent, 'other', 'GET', 'web.internal_file', 'open', 200],
    'admin · web internal file' => [Role::Admin, 'other', 'GET', 'web.internal_file', 'open', 200],
    'customer · own · api internal file' => [Role::Customer, 'own', 'GET', 'api.internal_file', 'open', 404],
    'customer · other · api internal file' => [Role::Customer, 'other', 'GET', 'api.internal_file', 'open', 404],
    'agent · api internal file' => [Role::Agent, 'other', 'GET', 'api.internal_file', 'open', 200],
    'admin · api internal file' => [Role::Admin, 'other', 'GET', 'api.internal_file', 'open', 200],
]);
```

- [ ] **Step 4: Run it**

```bash
./vendor/bin/pest tests/Feature/Authorization/ConversationAuthorizationTest.php
```
Expected: PASS, 35 tests. A failure points to a policy gap (T04) or a route missing `auth`: fix T07 routes, or stop and report a T04 gap.

- [ ] **Step 5: Style**

```bash
./vendor/bin/pint tests/Feature/Conversation tests/Feature/Authorization/ConversationAuthorizationTest.php
```
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add tests/Feature/Conversation/ReplyVisibilityTest.php tests/Feature/Authorization/ConversationAuthorizationTest.php
git commit -m "test(conversation): cover internal-note leakage and route access" --trailer "Refs: T07.4"
```

---

### T07.5 — Conversation UI

No JS test runner exists (TD-030): the checks are `vue-tsc` and `npm run build`, plus a manual smoke run.

- [ ] **Step 1: Run the type check before changing anything, to record the baseline**

```bash
npx vue-tsc --noEmit
```
Expected: PASS (exit 0) on the T05 stubs.

- [ ] **Step 2: Create** `resources/js/lib/attachments.ts`

```ts
// Client-side hints only. The server (StoreReplyRequest, built from config/attachments.php) is the authority;
// these mirror its defaults so users get feedback before uploading 10 MB for nothing.
export const ATTACHMENT_EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'txt', 'csv', 'doc', 'docx', 'xls', 'xlsx', 'zip'] as const;
export const ATTACHMENT_MAX_KB = 10240;
export const ATTACHMENT_MAX_FILES = 5;

export const ATTACHMENT_ACCEPT = ATTACHMENT_EXTENSIONS.map((extension) => `.${extension}`).join(',');

export function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }
    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export function attachmentHint(): string {
    return `Up to ${ATTACHMENT_MAX_FILES} files, ${formatBytes(ATTACHMENT_MAX_KB * 1024)} each. Allowed: ${ATTACHMENT_EXTENSIONS.join(', ')}.`;
}

export function checkFiles(files: File[]): string[] {
    const problems: string[] = [];

    if (files.length > ATTACHMENT_MAX_FILES) {
        problems.push(`You selected ${files.length} files; at most ${ATTACHMENT_MAX_FILES} are allowed.`);
    }

    for (const file of files) {
        const extension = file.name.includes('.') ? file.name.split('.').pop()!.toLowerCase() : '';
        if (!(ATTACHMENT_EXTENSIONS as readonly string[]).includes(extension)) {
            problems.push(`${file.name}: this file type is not allowed.`);
        }
        if (file.size > ATTACHMENT_MAX_KB * 1024) {
            problems.push(`${file.name}: larger than ${formatBytes(ATTACHMENT_MAX_KB * 1024)}.`);
        }
    }

    return problems;
}
```

- [ ] **Step 3: Replace** `resources/js/components/tickets/AttachmentList.vue`

```vue
<script setup lang="ts">
import { formatBytes } from '@/lib/attachments';
import type { AttachmentItem } from '@/types/tickets';
import { Download, Paperclip } from 'lucide-vue-next';

defineProps<{
    attachments: AttachmentItem[];
}>();

// A plain anchor (not an Inertia <Link>) to T05's download_url: the response is a file with Content-Disposition: attachment.
</script>

<template>
    <ul v-if="attachments.length > 0" class="flex flex-col gap-1.5" aria-label="Attachments">
        <li v-for="attachment in attachments" :key="attachment.id">
            <a
                :href="attachment.download_url"
                class="group inline-flex max-w-full items-center gap-2 rounded-md border border-border bg-background px-2.5 py-1.5 text-sm hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <Paperclip class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                <span class="truncate">{{ attachment.original_name }}</span>
                <span class="shrink-0 text-xs text-muted-foreground">{{ formatBytes(attachment.size_bytes) }}</span>
                <Download class="size-4 shrink-0 text-muted-foreground group-hover:text-foreground" aria-hidden="true" />
                <span class="sr-only">Download</span>
            </a>
        </li>
    </ul>
</template>
```

- [ ] **Step 4: Replace** `resources/js/components/tickets/ConversationPanel.vue`

```vue
<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import AttachmentList from '@/components/tickets/AttachmentList.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { ATTACHMENT_ACCEPT, attachmentHint, checkFiles } from '@/lib/attachments';
import type { ReplyItem, TicketCan, TicketDetail } from '@/types/tickets';
import { useForm } from '@inertiajs/vue3';
import { Lock, MessageSquare } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    ticket: TicketDetail;
    replies: ReplyItem[];
    can: TicketCan;
}>();

type ReplyForm = {
    body: string;
    is_internal: boolean;
    attachments: File[];
};

const form = useForm<ReplyForm>({
    body: '',
    is_internal: false,
    attachments: [],
});

const fileInput = ref<HTMLInputElement | null>(null);
const fileHints = ref<string[]>([]);

const isClosed = computed(() => props.ticket.status.value === 'closed');
const canPost = computed(() => props.can.reply && !isClosed.value);
const submitLabel = computed(() => (form.is_internal ? 'Add internal note' : 'Send reply'));

// Server-side keys look like "attachments" or "attachments.2"; show them all under the file input.
const attachmentErrors = computed(() =>
    Object.entries(form.errors as Record<string, string | undefined>)
        .filter(([key, message]) => key.startsWith('attachments') && message !== undefined)
        .map(([, message]) => message as string),
);

function formatDate(value: string | null): string {
    return value === null ? '' : new Date(value).toLocaleString();
}

function onFilesSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);
    fileHints.value = checkFiles(files);
    form.attachments = files;
}

function submit(): void {
    form.transform((data) => ({
        ...data,
        is_internal: props.can.addInternalNote ? data.is_internal : false,
    })).post(`/tickets/${encodeURIComponent(props.ticket.number)}/replies`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            fileHints.value = [];
            if (fileInput.value) {
                fileInput.value.value = '';
            }
        },
    });
}
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle class="flex items-center gap-2 text-base">
                <MessageSquare class="size-4" aria-hidden="true" />
                Conversation
            </CardTitle>
        </CardHeader>

        <CardContent class="flex flex-col gap-6">
            <p v-if="replies.length === 0" class="text-sm text-muted-foreground">No replies yet.</p>

            <ol v-else class="flex flex-col gap-4" aria-label="Conversation timeline">
                <li
                    v-for="reply in replies"
                    :key="reply.id"
                    :class="[
                        'rounded-lg border p-4',
                        reply.is_internal
                            ? 'border-dashed border-amber-400 bg-amber-50 dark:border-amber-600 dark:bg-amber-950/40'
                            : 'border-border bg-card',
                    ]"
                >
                    <div class="mb-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                        <span class="font-medium">{{ reply.author.name }}</span>
                        <time :datetime="reply.created_at ?? undefined" class="text-muted-foreground">{{ formatDate(reply.created_at) }}</time>
                        <span
                            v-if="reply.is_internal"
                            class="inline-flex items-center gap-1 rounded-full bg-amber-200 px-2 py-0.5 text-xs font-medium text-amber-900 dark:bg-amber-800 dark:text-amber-100"
                        >
                            <Lock class="size-3" aria-hidden="true" />
                            Internal note · not visible to the customer
                        </span>
                    </div>
                    <p class="text-sm break-words whitespace-pre-line">{{ reply.body }}</p>
                    <AttachmentList v-if="reply.attachments.length > 0" class="mt-3" :attachments="reply.attachments" />
                </li>
            </ol>

            <p v-if="isClosed" class="rounded-md border border-border bg-muted px-3 py-2 text-sm text-muted-foreground">
                This ticket is closed and no longer accepts replies.
            </p>

            <form v-else-if="canPost" class="flex flex-col gap-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="reply-body">{{ form.is_internal ? 'Internal note' : 'Your reply' }}</Label>
                    <textarea
                        id="reply-body"
                        v-model="form.body"
                        rows="5"
                        required
                        maxlength="20000"
                        :class="[
                            'flex min-h-24 w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50',
                            form.is_internal ? 'border-amber-400 dark:border-amber-600' : 'border-input',
                        ]"
                        :placeholder="form.is_internal ? 'Visible to staff only' : 'Write a reply'"
                    />
                    <InputError :message="form.errors.body" />
                </div>

                <div v-if="can.addInternalNote" class="flex items-center gap-2">
                    <input
                        id="reply-internal"
                        v-model="form.is_internal"
                        type="checkbox"
                        class="size-4 rounded border-input accent-amber-500"
                    />
                    <Label for="reply-internal" class="flex items-center gap-1">
                        <Lock class="size-3.5" aria-hidden="true" />
                        Internal note (staff only)
                    </Label>
                </div>

                <div class="grid gap-2">
                    <Label for="reply-attachments">Attachments</Label>
                    <input
                        id="reply-attachments"
                        ref="fileInput"
                        type="file"
                        multiple
                        :accept="ATTACHMENT_ACCEPT"
                        class="text-sm file:mr-3 file:rounded-md file:border file:border-input file:bg-background file:px-3 file:py-1.5 file:text-sm"
                        aria-describedby="reply-attachments-hint"
                        @change="onFilesSelected"
                    />
                    <p id="reply-attachments-hint" class="text-xs text-muted-foreground">{{ attachmentHint() }}</p>
                    <ul v-if="fileHints.length > 0" class="text-xs text-amber-700 dark:text-amber-400">
                        <li v-for="hint in fileHints" :key="hint">{{ hint }}</li>
                    </ul>
                    <InputError v-for="message in attachmentErrors" :key="message" :message="message" />
                </div>

                <div class="flex justify-end">
                    <Button type="submit" :disabled="form.processing || form.body.trim() === ''">
                        {{ submitLabel }}
                    </Button>
                </div>
            </form>
        </CardContent>
    </Card>
</template>
```

- [ ] **Step 5: Type check and build, expect PASS**

```bash
npx vue-tsc --noEmit && npm run build
```
Expected: `vue-tsc` exits 0; Vite prints `✓ built in …`. If `vue-tsc` reports a missing field on `ReplyItem`, `AttachmentItem` or `TicketDetail`, the T05 types differ from the fields listed in the Contract notes: stop and report; don't edit `types/tickets.ts`.

- [ ] **Step 6: Manual smoke run** (`composer run dev`, demo data from `DemoSeeder`)
  - As `agent1@example.com`: open a ticket, post a reply with a PDF, then post an internal note. The note renders with the dashed amber border and the "Internal note" badge.
  - As that ticket's customer: the note is absent, the toggle is absent, and the PDF downloads (it doesn't open inline).
  - Select a `.exe` file: the amber hint appears. Submit anyway: the server's 422 message shows under the file input.

- [ ] **Step 7: Commit**

```bash
git add resources/js/lib/attachments.ts resources/js/components/tickets/ConversationPanel.vue resources/js/components/tickets/AttachmentList.vue
git commit -m "feat(conversation): add timeline, reply form and attachment list" --trailer "Refs: T07.5"
```

---

## 6. Acceptance criteria

- [ ] `AddReply::handle` matches README §3.5 exactly and throws `TicketClosed` (422, `ticket_closed`) on a Closed ticket, for every author. Over HTTP, `TicketPolicy::reply` refuses Closed tickets first (403); a ticket closed between authorization and the locked re-read yields 422 `ticket_closed`.
- [ ] A customer's reply is always `is_internal = false`, on the web and the API, even when `is_internal=1` is posted.
- [ ] Reply and attachments are written in one transaction. A failure after files are stored leaves no files, no reply and no attachment rows.
- [ ] A public reply by the ticket's customer on a `WaitingForCustomer` ticket moves it to `InProgress` through `ChangeTicketStatus(null, …)`, and the SLA due time shifts by the paused business time. A staff reply doesn't change the status.
- [ ] `TicketReplied` is dispatched (after commit) with ticket, reply and author.
- [ ] Attachment rules come from `config/attachments.php`. Disallowed type (by content or by name), oversize, and too many files → 422. Each allowed type is accepted.
- [ ] Stored paths are `attachments/{ticket_id}/{uuid}.{ext}`; the original name exists only in `original_name`.
- [ ] Downloads go through `AttachmentPolicy::view` and respond with `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff`. Customers can never download an internal attachment (404), and nobody can download from a ticket they can't see (404).
- [ ] Customers never receive internal replies through the web `replies` prop, the API replies index, or the API ticket show. `TicketReplyResource` refuses to serialize an internal reply for a viewer who can't see it.
- [ ] Routes and names match README §3.10; the matrix test covers every one of them for guest, owner, stranger, agent and admin.
- [ ] `ConversationPanel.vue` renders internal notes distinctly; the internal toggle renders only when `can.addInternalNote`; the form is hidden on Closed tickets. No `v-html`.
- [ ] The replies index eager-loads `author:id,name` and column-limited attachments; no lazy-loading violations.

## 7. Verification

```bash
./vendor/bin/pint --test
./vendor/bin/phpstan analyse --memory-limit=1G
./vendor/bin/pest --parallel
npm run build && npx vue-tsc --noEmit
```
Expected: Pint reports no changes; PHPStan `[OK] No errors`; Pest all green, including the 80 T07 tests (`tests/Feature/Conversation/*` and `tests/Feature/Authorization/ConversationAuthorizationTest.php`); the build and `vue-tsc` exit 0.

```bash
./vendor/bin/pest --filter="internal" tests/Feature/Conversation tests/Feature/Authorization/ConversationAuthorizationTest.php
```
Expected: PASS. This is the reviewer's quick check that the leakage guards exist.

## Contract notes for controller

1. Resolved: `config/attachments.php` is created by T05 (5.1) with `disk`, `directory`, `max_kb`, `max_files` and `extensions`. T07 uses `disk`, `extensions`, `max_kb` and `max_files`.
2. Resolved: `TicketClosed` is built with T01's constructor, `new TicketClosed(string $message = default)`. T07 passes a message that names the ticket number.
3. Resolved: `ReplyItem` and `AttachmentItem` come from T05's `resources/js/types/tickets.ts`. In particular, `author` is a non-null `UserSummary`, `created_at` is nullable, and attachments carry `download_url`. `TicketReplyResource` emits the same reply shape as T05's web `replyItems()`.
4. Resolved: `AttachmentList.vue` links to T05's `AttachmentResource.download_url` (a path-based URL, `/attachments/{id}` or `/api/v1/attachments/{id}`), so it doesn't depend on the order in which routes are registered.
5. Resolved: T04's `AttachmentPolicy::view` returns 404 for a ticket the user can't see and for an internal attachment they can't see. The tests now assert exact 404s.
6. Resolved, following T04: `TicketPolicy::reply` denies Closed tickets (403) for every role, so the HTTP layer answers 403. `TicketClosed` (422 `ticket_closed`) remains the domain guard. It's tested at the Action level for both roles, and over HTTP for a ticket that closes between authorization and the locked re-read (simulated by updating the row when `AddReply` is resolved).
7. Open: the client-side attachment limits are duplicated in `resources/js/lib/attachments.ts`, because `ConversationPanel`'s props are fixed and T07 can't add a shared prop. Suggestion: share `attachments: {extensions, max_kb, max_files}` from config in a later task, and have the hints read it.
8. Resolved: the matrix uses T04's `AuthorizationMatrix::for($this)` with `actingAsRole()`, `asGuest()` and `expectAccess()`.
9. README §3.7 lists `ResumeSlaOnCustomerReply` as "none", and T07 keeps it that way: no listener class is created, and the resume happens inline in `AddReply`.
