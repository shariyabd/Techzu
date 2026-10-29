# T10 — Notifications

> **For agentic workers:** REQUIRED SUB-SKILL: use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax. Work test-first. Run the gate before each commit.

## 1. Header

- **Objective:** notify the right people about ticket events: queued mail plus database notifications, with spec §11 recipients exactly, internal notes going only to the assignee's in-app inbox, and no self-notifications. Expose the inbox on the web and API, with an unread badge in the navigation.
- **Depends on:** T05 (all events in README §3.6, `tickets.show` route), T07 (the reply endpoint, used by one end-to-end leakage test), and transitively T00 (`HandleInertiaRequests`, navigation registry, AppLayout) and T01 (models, `User::scopeAdmins`, `Notifiable`, the `notifications` table).
- **Wave:** 4b (parallel with T11, T12, T13).
- **TDs implemented:** TD-070 (side effects in listeners; Actions never notify), TD-071 (`ShouldQueue`, `notifications` queue, `afterCommit`, mail + database, internal notes database-only, the actor never notified, recipient rules in listeners), TD-072 (the `notifications` queue is isolated from `default` and `imports`), TD-120 (third internal-note leakage layer: the notification listeners), TD-021 (the unread count uses `notifications(notifiable_type, notifiable_id, read_at)`), spec §13 step 4 (imported tickets: no per-ticket notifications, one summary to the importer).

## 2. Files

**Create**

| Path | Purpose |
|---|---|
| `app/Notifications/BaseNotification.php` | abstract: queue, afterCommit, channels, payload `{ticket_number, title, body, url}`, mail |
| `app/Notifications/TicketCreatedNotification.php` | |
| `app/Notifications/TicketAssignedNotification.php` | |
| `app/Notifications/TicketRepliedNotification.php` | refuses internal replies |
| `app/Notifications/InternalNoteAddedNotification.php` | database only |
| `app/Notifications/TicketResolvedNotification.php` | |
| `app/Notifications/SlaApproachingNotification.php` | |
| `app/Notifications/SlaBreachedNotification.php` | |
| `app/Notifications/TicketImportFinishedNotification.php` | database only |
| `app/Services/Notifications/Recipients.php` | user lookup (id, name, email), admins, de-duplication, actor exclusion |
| `app/Listeners/SendTicketCreatedNotifications.php` | |
| `app/Listeners/SendTicketAssignedNotifications.php` | |
| `app/Listeners/SendTicketRepliedNotifications.php` | |
| `app/Listeners/SendTicketResolvedNotifications.php` | |
| `app/Listeners/SendSlaNotifications.php` | `handleApproaching`, `handleBreached` (both auto-discovered) |
| `app/Listeners/SendImportFinishedNotification.php` | |
| `app/Actions/Notifications/MarkNotificationRead.php` | owner-scoped |
| `app/Actions/Notifications/MarkAllNotificationsRead.php` | owner-scoped |
| `app/Services/Notifications/NotificationFeed.php` | the paginated inbox query |
| `app/Http/Requests/IndexNotificationsRequest.php` | `per_page`, `unread` |
| `app/Http/Requests/MarkNotificationReadRequest.php` | authenticated actor |
| `app/Http/Controllers/Web/NotificationController.php` | `index`, `read`, `readAll` |
| `app/Http/Controllers/Api/V1/NotificationController.php` | `index`, `read`, `readAll` |
| `app/Http/Resources/V1/NotificationResource.php` | |
| `routes/web/notifications.php`, `routes/api/v1/notifications.php` | |
| `resources/js/types/notifications.ts` | `NotificationItem`, `NotificationPage` |
| `resources/js/components/notifications/NotificationBell.vue` | bell with an unread badge |
| `resources/js/config/navigation/notifications.ts` | nav module that mounts the bell (see §3, "Bell mounting") |
| `resources/js/pages/notifications/Index.vue` | the inbox |
| `tests/Feature/Notifications/NotificationQueueingTest.php` | ShouldQueue, queue name, afterCommit, real dispatch, payload, mail URL, channels |
| `tests/Feature/Notifications/NotificationRecipientsTest.php` | a recipients dataset per event, plus self-exclusion |
| `tests/Feature/Notifications/InternalNoteLeakageTest.php` | the internal note never reaches the customer |
| `tests/Feature/Notifications/NotificationEndpointsTest.php` | list, mark read, mark all read, owner scoping, `unread_count` |
| `tests/Feature/Authorization/NotificationAuthorizationTest.php` | role × route matrix |

**Modify**

| Path | Change |
|---|---|
| `app/Http/Middleware/HandleInertiaRequests.php` | **only** the value of the `notifications` key in `share()` (README §3.13, §4) |

**Must not touch:** `resources/js/layouts/AppLayout.vue`, `resources/js/config/navigation.ts`, `bootstrap/*`, `app/Providers/*`, `app/Events/*`, `app/Actions/Tickets/*`, `app/Actions/Conversation/*`, `app/Models/*`, `config/*`, every other key in `HandleInertiaRequests::share()`, migrations.

## 3. Interfaces

### Consumes

```php
// T05 events (README §3.6), all ShouldDispatchAfterCommit
TicketCreated(Ticket $ticket, ?User $actor, bool $imported = false)
TicketAssigned(Ticket $ticket, ?User $actor, User $agent, ?User $previousAgent, AssignmentMethod $method)
TicketReplied(Ticket $ticket, TicketReply $reply, User $author)
TicketResolved(Ticket $ticket, ?User $actor)
TicketSlaApproaching(Ticket $ticket)
TicketSlaBreached(Ticket $ticket)
TicketImportFinished(TicketImport $import)
// T01
User (Notifiable; scopeAdmins), Ticket (customer_id, assigned_agent_id, number, subject, sla_due_at), TicketReply (is_internal, body), TicketImport (user_id, original_name, succeeded_rows, failed_rows)
// T00
HandleInertiaRequests::share() key 'notifications' (T00 default: unread_count 0)
resources/js/config/navigation.ts: re-exports `type NavItem = { title; href; icon?: Component; order; visible: (page: SharedProps) => boolean }` (defined in T00's `@/config/navigation`; the kit's own `@/types/navigation` keeps `BreadcrumbItem`); AppSidebar renders `<component :is="item.icon" v-if="item.icon" />`
resources/js/types/shared.ts: `SharedProps` with `notifications.unread_count: number`
// T04
Tests\Support\AuthorizationMatrix::for($this)->actingAsRole(Role)/asGuest()/expectAccess($method, $uri, $status, $payload = [])
// T06
config('tickets.auto_assign')
// T07 (test only)
route('api.v1.tickets.replies.store')
```

### Produces (README §3.9, §3.7, §3.10, §3.12, §3.13, exactly)

```php
// app/Notifications — all ShouldQueue, ->onQueue('notifications'), afterCommit(); database payload {ticket_number, title, body, url}
TicketCreatedNotification(Ticket $ticket)                         // mail, database
TicketAssignedNotification(Ticket $ticket, User $agent)           // mail, database
TicketRepliedNotification(Ticket $ticket, TicketReply $reply)     // mail, database; LogicException for an internal reply
InternalNoteAddedNotification(Ticket $ticket, TicketReply $reply) // database only
TicketResolvedNotification(Ticket $ticket)                        // mail, database
SlaApproachingNotification(Ticket $ticket)                        // mail, database
SlaBreachedNotification(Ticket $ticket)                           // mail, database
TicketImportFinishedNotification(TicketImport $import)            // database only; ticket_number null

// app/Listeners — final, sync, auto-discovered
SendTicketCreatedNotifications::handle(TicketCreated $event): void
SendTicketAssignedNotifications::handle(TicketAssigned $event): void
SendTicketRepliedNotifications::handle(TicketReplied $event): void
SendTicketResolvedNotifications::handle(TicketResolved $event): void
SendSlaNotifications::handleApproaching(TicketSlaApproaching $event): void
SendSlaNotifications::handleBreached(TicketSlaBreached $event): void
SendImportFinishedNotification::handle(TicketImportFinished $event): void

// Routes
// web (routes/web/notifications.php):    GET /notifications notifications.index · POST /notifications/{id}/read notifications.read · POST /notifications/read-all notifications.read-all
// API (routes/api/v1/notifications.php):  same paths under /api/v1, names api.v1.notifications.{index,read,read-all}
// API bodies: index → NotificationResource collection (paginated); read → NotificationResource; read-all → 204
// Web index: Inertia 'notifications/Index' with props items (paginated NotificationResource collection), unreadOnly

App\Http\Resources\V1\NotificationResource   // id, type, ticket_number, title, body, url, read_at, created_at

// Shared Inertia prop (HandleInertiaRequests, T10's closure only)
notifications.unread_count: int
```

Task-internal: `BaseNotification`, `Recipients`, `NotificationFeed`, `MarkNotificationRead::handle(User, string $id): DatabaseNotification`, `MarkAllNotificationsRead::handle(User): int`, the two FormRequests.

### Recipient rules (spec §11, applied in the listeners)

| Event | Recipients | Notification | Channels |
|---|---|---|---|
| `TicketCreated`, not imported | the customer (confirmation); all admins when `tickets.auto_assign` is false | `TicketCreatedNotification` | mail, database |
| `TicketCreated`, imported | nobody (the admin gets `TicketImportFinishedNotification` once) | — | — |
| `TicketAssigned` | the assigned agent; the customer | `TicketAssignedNotification` | mail, database |
| `TicketReplied`, public, author ≠ ticket customer | the customer | `TicketRepliedNotification` | mail, database |
| `TicketReplied`, public, author = ticket customer | the assigned agent, or all admins if unassigned | `TicketRepliedNotification` | mail, database |
| `TicketReplied`, internal | the assigned agent if they're not the author. **Never the customer.** | `InternalNoteAddedNotification` | database |
| `TicketResolved` | the customer | `TicketResolvedNotification` | mail, database |
| `TicketSlaApproaching` | the assigned agent, or all admins if unassigned | `SlaApproachingNotification` | mail, database |
| `TicketSlaBreached` | the assigned agent + all admins (de-duplicated) | `SlaBreachedNotification` | mail, database |
| `TicketImportFinished` | the importing admin | `TicketImportFinishedNotification` | database |

Every listener removes the event's actor (or reply author) from its recipients. The one exception is the creation confirmation, which is the customer's receipt for their own action (Contract note 1). Users are looked up fresh with `id, name, email`. Listeners never read a relation from the event's ticket, so lazy loading can't trigger, and a relation eager-loaded without `email` can't break mail.

### Bell mounting

`AppLayout` (T00) can't be edited, and T00 provides no header slot or header registry. It does render navigation from the per-feature registry, `resources/js/config/navigation/*.ts`: each `NavItem` has an `icon?: Component`, which `AppSidebar` renders as `<component :is="item.icon" />`. T10 adds `navigation/notifications.ts` with a *Notifications* item linking to `/notifications`, whose `icon` is `NotificationBell.vue`. The bell reads `usePage().props.notifications.unread_count` reactively and draws the badge, so the count appears wherever the layout draws nav icons, and no shared file is edited. `NotificationBell` takes no props, so a future header slot can mount it unchanged (Contract note 3).

## 4. Subtasks & mini-waves

| Subtask | Files | Depends on | Parallel-safe with |
|---|---|---|---|
| T10.1 Notification classes | `app/Notifications/*`, `NotificationQueueingTest.php` (the real-dispatch test is added in T10.2) | — | T10.3 |
| T10.2 Listeners & recipients | `Recipients.php`, `app/Listeners/Send*`, `NotificationRecipientsTest.php`, `InternalNoteLeakageTest.php`, append to `NotificationQueueingTest.php` | T10.1 | T10.3 |
| T10.3 Inbox endpoints | `Actions/Notifications/*`, `NotificationFeed.php`, both requests, both controllers, `NotificationResource.php`, both route files, `HandleInertiaRequests.php` (closure), `NotificationEndpointsTest.php`, `NotificationAuthorizationTest.php` | — (stores rows directly) | T10.1, T10.2 |
| T10.4 UI | `types/notifications.ts`, `NotificationBell.vue`, `navigation/notifications.ts`, `pages/notifications/Index.vue` | T10.3 (props) | T10.2 |

Mini-waves: **A** T10.1 ∥ T10.3 → **B** T10.2 ∥ T10.4.

---

## 5. Subtasks

### T10.1 — Notification classes

- [ ] **Step 1: Write the failing test** `tests/Feature/Notifications/NotificationQueueingTest.php`

```php
<?php

declare(strict_types=1);

use App\Enums\ImportStatus;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketImport;
use App\Models\TicketReply;
use App\Models\User;
use App\Notifications\BaseNotification;
use App\Notifications\InternalNoteAddedNotification;
use App\Notifications\SlaApproachingNotification;
use App\Notifications\SlaBreachedNotification;
use App\Notifications\TicketAssignedNotification;
use App\Notifications\TicketCreatedNotification;
use App\Notifications\TicketImportFinishedNotification;
use App\Notifications\TicketRepliedNotification;
use App\Notifications\TicketResolvedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

beforeEach(function (): void {
    $this->customer = User::factory()->customer()->create();
    $this->agent = User::factory()->agent()->create();
    $this->admin = User::factory()->admin()->create();
    $this->ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($this->agent)->create([
        'customer_id' => $this->customer->id,
        'created_by_id' => $this->customer->id,
        'subject' => 'Printer on fire',
    ]);
    $this->publicReply = TicketReply::factory()->for($this->ticket)->for($this->agent, 'author')->create(['body' => 'Fire brigade called.']);
    $this->internalReply = TicketReply::factory()->for($this->ticket)->for($this->agent, 'author')->internal()->create(['body' => 'Customer caused it.']);
    $this->import = (new TicketImport)->forceFill([
        'id' => 42,
        'user_id' => $this->admin->id,
        'original_name' => 'legacy.csv',
        'status' => ImportStatus::CompletedWithErrors,
        'total_rows' => 10,
        'processed_rows' => 10,
        'succeeded_rows' => 8,
        'failed_rows' => 2,
    ]);
});

it('is queued on the notifications queue after commit', function (BaseNotification $notification): void {
    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->and($notification->queue)->toBe('notifications')
        ->and($notification->afterCommit)->toBeTrue();
})->with([
    'created' => [fn (): BaseNotification => new TicketCreatedNotification($this->ticket)],
    'assigned' => [fn (): BaseNotification => new TicketAssignedNotification($this->ticket, $this->agent)],
    'replied' => [fn (): BaseNotification => new TicketRepliedNotification($this->ticket, $this->publicReply)],
    'internal note' => [fn (): BaseNotification => new InternalNoteAddedNotification($this->ticket, $this->internalReply)],
    'resolved' => [fn (): BaseNotification => new TicketResolvedNotification($this->ticket)],
    'sla approaching' => [fn (): BaseNotification => new SlaApproachingNotification($this->ticket)],
    'sla breached' => [fn (): BaseNotification => new SlaBreachedNotification($this->ticket)],
    'import finished' => [fn (): BaseNotification => new TicketImportFinishedNotification($this->import)],
]);

it('stores the documented database payload for ticket notifications', function (BaseNotification $notification): void {
    $payload = $notification->toArray($this->customer);

    expect(array_keys($payload))->toBe(['ticket_number', 'title', 'body', 'url'])
        ->and($payload['ticket_number'])->toBe($this->ticket->number)
        ->and($payload['url'])->toBe(route('tickets.show', $this->ticket))
        ->and($payload['title'])->toBeString()->not->toBe('')
        ->and($payload['body'])->toBeString()->not->toBe('');
})->with([
    'created' => [fn (): BaseNotification => new TicketCreatedNotification($this->ticket)],
    'assigned' => [fn (): BaseNotification => new TicketAssignedNotification($this->ticket, $this->agent)],
    'replied' => [fn (): BaseNotification => new TicketRepliedNotification($this->ticket, $this->publicReply)],
    'internal note' => [fn (): BaseNotification => new InternalNoteAddedNotification($this->ticket, $this->internalReply)],
    'resolved' => [fn (): BaseNotification => new TicketResolvedNotification($this->ticket)],
    'sla approaching' => [fn (): BaseNotification => new SlaApproachingNotification($this->ticket)],
    'sla breached' => [fn (): BaseNotification => new SlaBreachedNotification($this->ticket)],
]);

it('stores the documented payload for the import summary', function (): void {
    $payload = (new TicketImportFinishedNotification($this->import))->toArray($this->admin);

    expect(array_keys($payload))->toBe(['ticket_number', 'title', 'body', 'url'])
        ->and($payload['ticket_number'])->toBeNull()
        ->and($payload['url'])->toBe(url('/admin/imports/42'))
        ->and($payload['body'])->toContain('8')->toContain('2');
});

it('sends mail with a link to the ticket', function (BaseNotification $notification): void {
    expect($notification->via($this->customer))->toBe(['mail', 'database'])
        ->and($notification->toMail($this->customer)->actionUrl)->toBe(route('tickets.show', $this->ticket));
})->with([
    'created' => [fn (): BaseNotification => new TicketCreatedNotification($this->ticket)],
    'assigned' => [fn (): BaseNotification => new TicketAssignedNotification($this->ticket, $this->agent)],
    'replied' => [fn (): BaseNotification => new TicketRepliedNotification($this->ticket, $this->publicReply)],
    'resolved' => [fn (): BaseNotification => new TicketResolvedNotification($this->ticket)],
    'sla approaching' => [fn (): BaseNotification => new SlaApproachingNotification($this->ticket)],
    'sla breached' => [fn (): BaseNotification => new SlaBreachedNotification($this->ticket)],
]);

it('keeps internal notes and import summaries in the database channel only', function (BaseNotification $notification): void {
    expect($notification->via($this->agent))->toBe(['database']);
})->with([
    'internal note' => [fn (): BaseNotification => new InternalNoteAddedNotification($this->ticket, $this->internalReply)],
    'import finished' => [fn (): BaseNotification => new TicketImportFinishedNotification($this->import)],
]);

it('refuses to build a public reply notification from an internal note', function (): void {
    expect(fn () => new TicketRepliedNotification($this->ticket, $this->internalReply))
        ->toThrow(LogicException::class);
});
```

- [ ] **Step 2: Run it, expect FAIL**

```bash
./vendor/bin/pest tests/Feature/Notifications/NotificationQueueingTest.php
```
Expected: FAIL with `Error: Class "App\Notifications\TicketCreatedNotification" not found` (every dataset row errors).

- [ ] **Step 3: Implement** `app/Notifications/BaseNotification.php`

```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

abstract class BaseNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        // Isolated from imports and auto-assign so user-facing mail is never stuck behind bulk work (TD-072).
        $this->onQueue('notifications');
        // Never announce state that a rolled-back transaction undid (TD-071).
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title($notifiable))
            ->line($this->body($notifiable))
            ->action($this->actionText(), $this->url());
    }

    /**
     * @return array{ticket_number: string|null, title: string, body: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'ticket_number' => $this->ticketNumber(),
            'title' => $this->title($notifiable),
            'body' => $this->body($notifiable),
            'url' => $this->url(),
        ];
    }

    abstract protected function title(object $notifiable): string;

    abstract protected function body(object $notifiable): string;

    abstract protected function url(): string;

    abstract protected function ticketNumber(): ?string;

    protected function actionText(): string
    {
        return 'View ticket';
    }
}
```

- [ ] **Step 4: Implement** `app/Notifications/TicketCreatedNotification.php`

```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\User;

final class TicketCreatedNotification extends BaseNotification
{
    public function __construct(public readonly Ticket $ticket)
    {
        parent::__construct();
    }

    protected function title(object $notifiable): string
    {
        return $this->isCustomer($notifiable)
            ? sprintf('We received your ticket %s', $this->ticket->number)
            : sprintf('New ticket %s', $this->ticket->number);
    }

    protected function body(object $notifiable): string
    {
        return $this->isCustomer($notifiable)
            ? sprintf('Your ticket "%s" has been created. We will reply as soon as possible.', $this->ticket->subject)
            : sprintf('"%s" is waiting to be assigned.', $this->ticket->subject);
    }

    protected function url(): string
    {
        return route('tickets.show', $this->ticket);
    }

    protected function ticketNumber(): ?string
    {
        return $this->ticket->number;
    }

    private function isCustomer(object $notifiable): bool
    {
        return $notifiable instanceof User && $notifiable->getKey() === $this->ticket->customer_id;
    }
}
```

- [ ] **Step 5: Implement** `app/Notifications/TicketAssignedNotification.php`

```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\User;

final class TicketAssignedNotification extends BaseNotification
{
    public function __construct(
        public readonly Ticket $ticket,
        public readonly User $agent,
    ) {
        parent::__construct();
    }

    protected function title(object $notifiable): string
    {
        return $this->isAgent($notifiable)
            ? sprintf('Ticket %s was assigned to you', $this->ticket->number)
            : sprintf('Your ticket %s has an agent', $this->ticket->number);
    }

    protected function body(object $notifiable): string
    {
        return $this->isAgent($notifiable)
            ? sprintf('"%s" is now yours.', $this->ticket->subject)
            : sprintf('%s is now handling "%s".', $this->agent->name, $this->ticket->subject);
    }

    protected function url(): string
    {
        return route('tickets.show', $this->ticket);
    }

    protected function ticketNumber(): ?string
    {
        return $this->ticket->number;
    }

    private function isAgent(object $notifiable): bool
    {
        return $notifiable instanceof User && $notifiable->getKey() === $this->agent->getKey();
    }
}
```

- [ ] **Step 6: Implement** `app/Notifications/TicketRepliedNotification.php`

```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Support\Str;
use LogicException;

final class TicketRepliedNotification extends BaseNotification
{
    public function __construct(
        public readonly Ticket $ticket,
        public readonly TicketReply $reply,
    ) {
        // This class reaches customers by mail; an internal note must never be its payload (TD-120).
        if ($reply->is_internal) {
            throw new LogicException('Internal notes use InternalNoteAddedNotification.');
        }

        parent::__construct();
    }

    protected function title(object $notifiable): string
    {
        return sprintf('New reply on %s', $this->ticket->number);
    }

    protected function body(object $notifiable): string
    {
        return Str::limit($this->reply->body, 160);
    }

    protected function url(): string
    {
        return route('tickets.show', $this->ticket);
    }

    protected function ticketNumber(): ?string
    {
        return $this->ticket->number;
    }
}
```

- [ ] **Step 7: Implement** `app/Notifications/InternalNoteAddedNotification.php`

```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Support\Str;

final class InternalNoteAddedNotification extends BaseNotification
{
    public function __construct(
        public readonly Ticket $ticket,
        public readonly TicketReply $reply,
    ) {
        parent::__construct();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        // In-app only: note text must not leave the system by mail (TD-071).
        return ['database'];
    }

    protected function title(object $notifiable): string
    {
        return sprintf('Internal note on %s', $this->ticket->number);
    }

    protected function body(object $notifiable): string
    {
        return Str::limit($this->reply->body, 160);
    }

    protected function url(): string
    {
        return route('tickets.show', $this->ticket);
    }

    protected function ticketNumber(): ?string
    {
        return $this->ticket->number;
    }
}
```

- [ ] **Step 8: Implement** `app/Notifications/TicketResolvedNotification.php`

```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Ticket;

final class TicketResolvedNotification extends BaseNotification
{
    public function __construct(public readonly Ticket $ticket)
    {
        parent::__construct();
    }

    protected function title(object $notifiable): string
    {
        return sprintf('Ticket %s was resolved', $this->ticket->number);
    }

    protected function body(object $notifiable): string
    {
        return sprintf('"%s" is resolved. Reopen it from the ticket page if the problem persists.', $this->ticket->subject);
    }

    protected function url(): string
    {
        return route('tickets.show', $this->ticket);
    }

    protected function ticketNumber(): ?string
    {
        return $this->ticket->number;
    }
}
```

- [ ] **Step 9: Implement** `app/Notifications/SlaApproachingNotification.php`

```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Ticket;

final class SlaApproachingNotification extends BaseNotification
{
    public function __construct(public readonly Ticket $ticket)
    {
        parent::__construct();
    }

    protected function title(object $notifiable): string
    {
        return sprintf('SLA approaching on %s', $this->ticket->number);
    }

    protected function body(object $notifiable): string
    {
        // Shown in business time, the clock the SLA is measured in.
        $due = $this->ticket->sla_due_at?->setTimezone((string) config('business_hours.timezone'))->format('D j M Y H:i T');

        return sprintf('"%s" is due %s.', $this->ticket->subject, $due ?? 'soon');
    }

    protected function url(): string
    {
        return route('tickets.show', $this->ticket);
    }

    protected function ticketNumber(): ?string
    {
        return $this->ticket->number;
    }
}
```

- [ ] **Step 10: Implement** `app/Notifications/SlaBreachedNotification.php`

```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Ticket;

final class SlaBreachedNotification extends BaseNotification
{
    public function __construct(public readonly Ticket $ticket)
    {
        parent::__construct();
    }

    protected function title(object $notifiable): string
    {
        return sprintf('SLA breached on %s', $this->ticket->number);
    }

    protected function body(object $notifiable): string
    {
        $due = $this->ticket->sla_due_at?->setTimezone((string) config('business_hours.timezone'))->format('D j M Y H:i T');

        return sprintf('"%s" passed its resolution deadline (%s) and has been escalated.', $this->ticket->subject, $due ?? 'unknown');
    }

    protected function url(): string
    {
        return route('tickets.show', $this->ticket);
    }

    protected function ticketNumber(): ?string
    {
        return $this->ticket->number;
    }
}
```

- [ ] **Step 11: Implement** `app/Notifications/TicketImportFinishedNotification.php`

```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\TicketImport;

final class TicketImportFinishedNotification extends BaseNotification
{
    public function __construct(public readonly TicketImport $import)
    {
        parent::__construct();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    protected function title(object $notifiable): string
    {
        return sprintf('Import of %s finished', $this->import->original_name);
    }

    protected function body(object $notifiable): string
    {
        return sprintf('%d tickets imported, %d rows failed.', $this->import->succeeded_rows, $this->import->failed_rows);
    }

    protected function url(): string
    {
        // A path, not route(): admin.imports.show is T11's, developed in parallel (Contract note 4).
        return url('/admin/imports/'.$this->import->getKey());
    }

    protected function ticketNumber(): ?string
    {
        return null;
    }

    protected function actionText(): string
    {
        return 'View import';
    }
}
```

- [ ] **Step 12: Run it, expect PASS**

```bash
./vendor/bin/pest tests/Feature/Notifications/NotificationQueueingTest.php
```
Expected: PASS, 25 tests.

- [ ] **Step 13: Style and static analysis**

```bash
./vendor/bin/pint app/Notifications tests/Feature/Notifications && ./vendor/bin/phpstan analyse --memory-limit=1G app/Notifications
```
Expected: Pint clean; PHPStan `[OK] No errors`.

- [ ] **Step 14: Commit**

```bash
git add app/Notifications tests/Feature/Notifications/NotificationQueueingTest.php
git commit -m "feat(notifications): add queued ticket notification classes" --trailer "Refs: T10.1"
```

---

### T10.2 — Listeners and recipient rules

- [ ] **Step 1: Write the failing test** `tests/Feature/Notifications/NotificationRecipientsTest.php`

```php
<?php

declare(strict_types=1);

use App\Enums\AssignmentMethod;
use App\Enums\ImportStatus;
use App\Enums\TicketStatus;
use App\Events\TicketAssigned;
use App\Events\TicketCreated;
use App\Events\TicketImportFinished;
use App\Events\TicketReplied;
use App\Events\TicketResolved;
use App\Events\TicketSlaApproaching;
use App\Events\TicketSlaBreached;
use App\Models\Ticket;
use App\Models\TicketImport;
use App\Models\TicketReply;
use App\Models\User;
use App\Notifications\InternalNoteAddedNotification;
use App\Notifications\SlaApproachingNotification;
use App\Notifications\SlaBreachedNotification;
use App\Notifications\TicketAssignedNotification;
use App\Notifications\TicketCreatedNotification;
use App\Notifications\TicketImportFinishedNotification;
use App\Notifications\TicketRepliedNotification;
use App\Notifications\TicketResolvedNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Notification::fake();
    Queue::fake(); // keeps T06's auto-assign job from running and assigning mid-test
    config(['tickets.auto_assign' => false]);

    $this->u = [
        'customer' => User::factory()->customer()->create(),
        'agent' => User::factory()->agent()->create(),
        'other_agent' => User::factory()->agent()->create(),
        'admin1' => User::factory()->admin()->create(),
        'admin2' => User::factory()->admin()->create(),
    ];

    $this->ticket = fn (?string $assignee = 'agent'): Ticket => Ticket::factory()
        ->status(TicketStatus::InProgress)
        ->create([
            'customer_id' => $this->u['customer']->id,
            'created_by_id' => $this->u['customer']->id,
            'assigned_agent_id' => $assignee === null ? null : $this->u[$assignee]->id,
        ]);

    $this->reply = fn (Ticket $ticket, string $author, bool $internal): TicketReply => TicketReply::factory()
        ->for($ticket)
        ->for($this->u[$author], 'author')
        ->create(['is_internal' => $internal]);

    // Expected users get the notification exactly once; everyone else not at all.
    $this->expectRecipients = function (string $notification, array $expected): void {
        foreach ($this->u as $key => $user) {
            in_array($key, $expected, true)
                ? Notification::assertSentToTimes($user, $notification, 1)
                : Notification::assertNotSentTo($user, $notification);
        }
    };
});

it('notifies on TicketCreated', function (?string $actor, bool $autoAssign, bool $imported, array $expected): void {
    config(['tickets.auto_assign' => $autoAssign]);

    event(new TicketCreated(($this->ticket)(null), $actor === null ? null : $this->u[$actor], $imported));

    ($this->expectRecipients)(TicketCreatedNotification::class, $expected);
})->with([
    'customer self-service, manual assignment' => ['customer', false, false, ['customer', 'admin1', 'admin2']],
    'agent on behalf of the customer' => ['agent', false, false, ['customer', 'admin1', 'admin2']],
    'admin on behalf: that admin is skipped' => ['admin1', false, false, ['customer', 'admin2']],
    'auto-assign on: admins are not told' => ['customer', true, false, ['customer']],
    'imported: nobody, not even the customer' => ['admin1', false, true, []],
]);

it('notifies on TicketAssigned', function (?string $actor, string $agent, AssignmentMethod $method, array $expected): void {
    $ticket = ($this->ticket)($agent);

    event(new TicketAssigned($ticket, $actor === null ? null : $this->u[$actor], $this->u[$agent], null, $method));

    ($this->expectRecipients)(TicketAssignedNotification::class, $expected);
})->with([
    'admin assigns' => ['admin1', 'agent', AssignmentMethod::Manual, ['agent', 'customer']],
    'agent claims: no notice to themselves' => ['agent', 'agent', AssignmentMethod::Claimed, ['customer']],
    'automatic assignment' => [null, 'agent', AssignmentMethod::Automatic, ['agent', 'customer']],
    'agent hands off to a colleague' => ['agent', 'other_agent', AssignmentMethod::Manual, ['other_agent', 'customer']],
]);

it('notifies on TicketReplied', function (string $author, ?string $assignee, bool $internal, string $notification, array $expected): void {
    $ticket = ($this->ticket)($assignee);

    event(new TicketReplied($ticket, ($this->reply)($ticket, $author, $internal), $this->u[$author]));

    ($this->expectRecipients)($notification, $expected);
})->with([
    'agent public reply → customer' => ['agent', 'agent', false, TicketRepliedNotification::class, ['customer']],
    'admin public reply → customer' => ['admin1', 'agent', false, TicketRepliedNotification::class, ['customer']],
    'customer reply → assigned agent' => ['customer', 'agent', false, TicketRepliedNotification::class, ['agent']],
    'customer reply, unassigned → admins' => ['customer', null, false, TicketRepliedNotification::class, ['admin1', 'admin2']],
    'internal note by a colleague → assignee' => ['other_agent', 'agent', true, InternalNoteAddedNotification::class, ['agent']],
    'internal note by the assignee → nobody' => ['agent', 'agent', true, InternalNoteAddedNotification::class, []],
    'internal note, unassigned → nobody' => ['admin1', null, true, InternalNoteAddedNotification::class, []],
]);

it('notifies on TicketResolved', function (?string $actor, array $expected): void {
    event(new TicketResolved(($this->ticket)('agent'), $actor === null ? null : $this->u[$actor]));

    ($this->expectRecipients)(TicketResolvedNotification::class, $expected);
})->with([
    'agent resolves' => ['agent', ['customer']],
    'system resolves' => [null, ['customer']],
    'the customer acted: no notice to themselves' => ['customer', []],
]);

it('notifies on SLA events', function (string $event, ?string $assignee, string $notification, array $expected): void {
    event(new $event(($this->ticket)($assignee)));

    ($this->expectRecipients)($notification, $expected);
})->with([
    'approaching, assigned → agent' => [TicketSlaApproaching::class, 'agent', SlaApproachingNotification::class, ['agent']],
    'approaching, unassigned → admins' => [TicketSlaApproaching::class, null, SlaApproachingNotification::class, ['admin1', 'admin2']],
    'breached, assigned → agent and admins' => [TicketSlaBreached::class, 'agent', SlaBreachedNotification::class, ['agent', 'admin1', 'admin2']],
    'breached, unassigned → admins' => [TicketSlaBreached::class, null, SlaBreachedNotification::class, ['admin1', 'admin2']],
    'breached, assigned to an admin → that admin once' => [TicketSlaBreached::class, 'admin1', SlaBreachedNotification::class, ['admin1', 'admin2']],
]);

it('notifies only the importing admin when an import finishes', function (): void {
    $import = (new TicketImport)->forceFill([
        'id' => 7,
        'user_id' => $this->u['admin1']->id,
        'original_name' => 'tickets.csv',
        'status' => ImportStatus::Completed,
        'succeeded_rows' => 3,
        'failed_rows' => 0,
    ]);

    event(new TicketImportFinished($import));

    ($this->expectRecipients)(TicketImportFinishedNotification::class, ['admin1']);
});

it('never notifies the actor about their own action', function (array $scenario): void {
    [$event, $actor] = $scenario;

    event($event);

    Notification::assertNothingSentTo($actor);
})->with([
    'agent claims' => [fn (): array => [
        new TicketAssigned(($this->ticket)('agent'), $this->u['agent'], $this->u['agent'], null, AssignmentMethod::Claimed),
        $this->u['agent'],
    ]],
    'admin creates on behalf' => [fn (): array => [new TicketCreated(($this->ticket)(null), $this->u['admin1']), $this->u['admin1']]],
    'assignee replies publicly' => [function (): array {
        $ticket = ($this->ticket)('agent');

        return [new TicketReplied($ticket, ($this->reply)($ticket, 'agent', false), $this->u['agent']), $this->u['agent']];
    }],
    'customer replies on an unassigned ticket' => [function (): array {
        $ticket = ($this->ticket)(null);

        return [new TicketReplied($ticket, ($this->reply)($ticket, 'customer', false), $this->u['customer']), $this->u['customer']];
    }],
    'assignee writes an internal note' => [function (): array {
        $ticket = ($this->ticket)('agent');

        return [new TicketReplied($ticket, ($this->reply)($ticket, 'agent', true), $this->u['agent']), $this->u['agent']];
    }],
    'admin replies to a ticket they are assigned' => [function (): array {
        $ticket = ($this->ticket)('admin1');

        return [new TicketReplied($ticket, ($this->reply)($ticket, 'admin1', false), $this->u['admin1']), $this->u['admin1']];
    }],
    'agent resolves' => [fn (): array => [new TicketResolved(($this->ticket)('agent'), $this->u['agent']), $this->u['agent']]],
]);

it('still sends the creation confirmation to a customer who opened their own ticket', function (): void {
    event(new TicketCreated(($this->ticket)(null), $this->u['customer']));

    Notification::assertSentToTimes($this->u['customer'], TicketCreatedNotification::class, 1);
});
```

- [ ] **Step 2: Write the failing test** `tests/Feature/Notifications/InternalNoteLeakageTest.php`

```php
<?php

declare(strict_types=1);

use App\Enums\TicketStatus;
use App\Events\TicketReplied;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Notifications\InternalNoteAddedNotification;
use App\Notifications\TicketRepliedNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    Notification::fake();
    Queue::fake();

    $this->customer = User::factory()->customer()->create();
    $this->agent = User::factory()->agent()->create();
    $this->colleague = User::factory()->agent()->create();
    $this->admin = User::factory()->admin()->create();
});

it('sends the customer nothing at all for an internal note', function (?string $assignee, string $author): void {
    $users = ['agent' => $this->agent, 'colleague' => $this->colleague, 'admin' => $this->admin];
    $ticket = Ticket::factory()->status(TicketStatus::InProgress)->create([
        'customer_id' => $this->customer->id,
        'created_by_id' => $this->customer->id,
        'assigned_agent_id' => $assignee === null ? null : $users[$assignee]->id,
    ]);
    $note = TicketReply::factory()->for($ticket)->for($users[$author], 'author')->internal()->create();

    event(new TicketReplied($ticket, $note, $users[$author]));

    Notification::assertNothingSentTo($this->customer);
})->with([
    'colleague writes, agent assigned' => ['agent', 'colleague'],
    'admin writes, unassigned' => [null, 'admin'],
    'assignee writes' => ['agent', 'agent'],
]);

it('delivers the internal note to the assignee in the database channel only', function (): void {
    $ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($this->agent)->create([
        'customer_id' => $this->customer->id,
        'created_by_id' => $this->customer->id,
    ]);
    $note = TicketReply::factory()->for($ticket)->for($this->colleague, 'author')->internal()->create();

    event(new TicketReplied($ticket, $note, $this->colleague));

    Notification::assertSentTo(
        $this->agent,
        InternalNoteAddedNotification::class,
        fn (InternalNoteAddedNotification $notification, array $channels): bool => $channels === ['database'],
    );
    Notification::assertNotSentTo($this->agent, TicketRepliedNotification::class);
});

it('keeps internal notes from customers end to end through the reply endpoint', function (): void {
    $ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($this->agent)->create([
        'customer_id' => $this->customer->id,
        'created_by_id' => $this->customer->id,
    ]);

    Sanctum::actingAs($this->colleague, ['*']);
    $this->postJson(route('api.v1.tickets.replies.store', $ticket), ['body' => 'Do not tell the customer', 'is_internal' => true])
        ->assertCreated();

    Notification::assertNothingSentTo($this->customer);
    Notification::assertSentTo($this->agent, InternalNoteAddedNotification::class);

    $this->postJson(route('api.v1.tickets.replies.store', $ticket), ['body' => 'Public answer'])->assertCreated();

    Notification::assertSentTo($this->customer, TicketRepliedNotification::class);
});
```

- [ ] **Step 3: Append the real-dispatch test** to `tests/Feature/Notifications/NotificationQueueingTest.php` (add the imports at the top of the file, and the test at the end)

```php
use App\Enums\AssignmentMethod;
use App\Events\TicketAssigned;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;
```

```php
it('pushes real notification jobs onto the notifications queue', function (): void {
    Queue::fake();

    event(new TicketAssigned($this->ticket, $this->admin, $this->agent, null, AssignmentMethod::Manual));

    // Agent + customer, each on mail + database.
    Queue::assertPushed(SendQueuedNotifications::class, 4);
    Queue::assertPushedOn('notifications', SendQueuedNotifications::class, fn (SendQueuedNotifications $job): bool => $job->notification instanceof TicketAssignedNotification);
    Queue::assertNotPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job): bool => $job->queue !== 'notifications');
});
```

- [ ] **Step 4: Run them, expect FAIL**

```bash
./vendor/bin/pest tests/Feature/Notifications/NotificationRecipientsTest.php tests/Feature/Notifications/InternalNoteLeakageTest.php tests/Feature/Notifications/NotificationQueueingTest.php
```
Expected: FAIL. Recipient rows fail with `The expected [App\Notifications\TicketCreatedNotification] notification was sent 0 times instead of 1 times.`; the real-dispatch test fails with `The expected [Illuminate\Notifications\SendQueuedNotifications] job was pushed 0 times instead of 4 times.` Rows whose expectation is "nobody" already pass.

- [ ] **Step 5: Implement** `app/Services/Notifications/Recipients.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\User;
use Illuminate\Support\Collection;

final class Recipients
{
    private const COLUMNS = ['id', 'name', 'email'];

    /**
     * Loaded fresh so a relation eager-loaded without `email` (or lazy loading) can never break mail.
     */
    public function find(?int $userId): ?User
    {
        return $userId === null ? null : User::query()->whereKey($userId)->first(self::COLUMNS);
    }

    /**
     * Admins are a small, staff-managed set, so a single bounded query is fine here.
     *
     * @return Collection<int, User>
     */
    public function admins(): Collection
    {
        return User::query()->admins()->orderBy('id')->get(self::COLUMNS);
    }

    /**
     * De-duplicates and removes the actor: nobody is notified about their own action (TD-071).
     *
     * @param  iterable<int, User|null>  $users
     * @return Collection<int, User>
     */
    public function excluding(iterable $users, ?User $actor): Collection
    {
        return collect($users)
            ->filter()
            ->unique(static fn (User $user): mixed => $user->getKey())
            ->reject(static fn (User $user): bool => $actor !== null && $user->getKey() === $actor->getKey())
            ->values();
    }
}
```

- [ ] **Step 6: Implement** `app/Listeners/SendTicketCreatedNotifications.php`

```php
<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TicketCreated;
use App\Notifications\TicketCreatedNotification;
use App\Services\Notifications\Recipients;
use Illuminate\Support\Facades\Notification;

final class SendTicketCreatedNotifications
{
    public function __construct(private readonly Recipients $recipients) {}

    public function handle(TicketCreated $event): void
    {
        // Imports would flood inboxes; the importing admin gets one summary instead (spec §13).
        if ($event->imported) {
            return;
        }

        $ticket = $event->ticket;

        // The confirmation is the customer's receipt, so it is sent even when they are the actor (Contract note 1).
        $customer = $this->recipients->find($ticket->customer_id);
        if ($customer !== null) {
            Notification::send($customer, new TicketCreatedNotification($ticket));
        }

        // With auto-assignment off, nobody else will pick the ticket up unless admins hear about it.
        if (! config('tickets.auto_assign')) {
            $admins = $this->recipients->excluding($this->recipients->admins(), $event->actor);
            Notification::send($admins, new TicketCreatedNotification($ticket));
        }
    }
}
```

- [ ] **Step 7: Implement** `app/Listeners/SendTicketAssignedNotifications.php`

```php
<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TicketAssigned;
use App\Notifications\TicketAssignedNotification;
use App\Services\Notifications\Recipients;
use Illuminate\Support\Facades\Notification;

final class SendTicketAssignedNotifications
{
    public function __construct(private readonly Recipients $recipients) {}

    public function handle(TicketAssigned $event): void
    {
        $recipients = $this->recipients->excluding([
            $this->recipients->find($event->agent->getKey()),
            $this->recipients->find($event->ticket->customer_id),
        ], $event->actor);

        Notification::send($recipients, new TicketAssignedNotification($event->ticket, $event->agent));
    }
}
```

- [ ] **Step 8: Implement** `app/Listeners/SendTicketRepliedNotifications.php`

```php
<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TicketReplied;
use App\Models\User;
use App\Notifications\InternalNoteAddedNotification;
use App\Notifications\TicketRepliedNotification;
use App\Services\Notifications\Recipients;
use Illuminate\Support\Facades\Notification;

final class SendTicketRepliedNotifications
{
    public function __construct(private readonly Recipients $recipients) {}

    public function handle(TicketReplied $event): void
    {
        $ticket = $event->ticket;
        $reply = $event->reply;
        $author = $event->author;

        if ($reply->is_internal) {
            // Third leakage layer (TD-120): only the assignee is a candidate, and never the customer.
            $assignee = $this->recipients->find($ticket->assigned_agent_id);
            $recipients = $this->recipients->excluding([$assignee], $author)
                ->reject(static fn (User $user): bool => $user->getKey() === $ticket->customer_id);

            Notification::send($recipients, new InternalNoteAddedNotification($ticket, $reply));

            return;
        }

        if ($author->getKey() === $ticket->customer_id) {
            $targets = $ticket->assigned_agent_id !== null
                ? [$this->recipients->find($ticket->assigned_agent_id)]
                : $this->recipients->admins();

            Notification::send($this->recipients->excluding($targets, $author), new TicketRepliedNotification($ticket, $reply));

            return;
        }

        $customer = $this->recipients->find($ticket->customer_id);
        Notification::send($this->recipients->excluding([$customer], $author), new TicketRepliedNotification($ticket, $reply));
    }
}
```

- [ ] **Step 9: Implement** `app/Listeners/SendTicketResolvedNotifications.php`

```php
<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TicketResolved;
use App\Notifications\TicketResolvedNotification;
use App\Services\Notifications\Recipients;
use Illuminate\Support\Facades\Notification;

final class SendTicketResolvedNotifications
{
    public function __construct(private readonly Recipients $recipients) {}

    public function handle(TicketResolved $event): void
    {
        $customer = $this->recipients->find($event->ticket->customer_id);

        Notification::send(
            $this->recipients->excluding([$customer], $event->actor),
            new TicketResolvedNotification($event->ticket),
        );
    }
}
```

- [ ] **Step 10: Implement** `app/Listeners/SendSlaNotifications.php`

```php
<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TicketSlaApproaching;
use App\Events\TicketSlaBreached;
use App\Notifications\SlaApproachingNotification;
use App\Notifications\SlaBreachedNotification;
use App\Services\Notifications\Recipients;
use Illuminate\Support\Facades\Notification;

final class SendSlaNotifications
{
    public function __construct(private readonly Recipients $recipients) {}

    public function handleApproaching(TicketSlaApproaching $event): void
    {
        $agent = $this->recipients->find($event->ticket->assigned_agent_id);

        // An unassigned ticket has no owner to warn, so whoever can assign it is told instead.
        $targets = $agent !== null ? [$agent] : $this->recipients->admins();

        Notification::send($this->recipients->excluding($targets, null), new SlaApproachingNotification($event->ticket));
    }

    public function handleBreached(TicketSlaBreached $event): void
    {
        $targets = [...$this->recipients->admins()->all(), $this->recipients->find($event->ticket->assigned_agent_id)];

        Notification::send($this->recipients->excluding($targets, null), new SlaBreachedNotification($event->ticket));
    }
}
```

- [ ] **Step 11: Implement** `app/Listeners/SendImportFinishedNotification.php`

```php
<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TicketImportFinished;
use App\Notifications\TicketImportFinishedNotification;
use App\Services\Notifications\Recipients;
use Illuminate\Support\Facades\Notification;

final class SendImportFinishedNotification
{
    public function __construct(private readonly Recipients $recipients) {}

    public function handle(TicketImportFinished $event): void
    {
        $admin = $this->recipients->find($event->import->user_id);

        if ($admin !== null) {
            Notification::send($admin, new TicketImportFinishedNotification($event->import));
        }
    }
}
```

- [ ] **Step 12: Confirm discovery**

```bash
php artisan event:list | grep -E "Send(Ticket|Sla|Import)"
```
Expected: seven listener lines. `TicketCreated`, `TicketAssigned`, `TicketReplied`, `TicketResolved` and `TicketImportFinished` each list their `Send…` listener; `TicketSlaApproaching` lists `SendSlaNotifications@handleApproaching`; `TicketSlaBreached` lists `SendSlaNotifications@handleBreached`.

- [ ] **Step 13: Run them, expect PASS**

```bash
./vendor/bin/pest tests/Feature/Notifications
```
Expected: PASS: `NotificationRecipientsTest` 33, `InternalNoteLeakageTest` 5, `NotificationQueueingTest` 26.

- [ ] **Step 14: Style and static analysis**

```bash
./vendor/bin/pint app/Listeners app/Services/Notifications tests/Feature/Notifications && ./vendor/bin/phpstan analyse --memory-limit=1G
```
Expected: Pint clean; PHPStan `[OK] No errors`.

- [ ] **Step 15: Commit**

```bash
git add app/Services/Notifications/Recipients.php app/Listeners/SendTicketCreatedNotifications.php \
  app/Listeners/SendTicketAssignedNotifications.php app/Listeners/SendTicketRepliedNotifications.php \
  app/Listeners/SendTicketResolvedNotifications.php app/Listeners/SendSlaNotifications.php \
  app/Listeners/SendImportFinishedNotification.php tests/Feature/Notifications/NotificationRecipientsTest.php \
  tests/Feature/Notifications/InternalNoteLeakageTest.php tests/Feature/Notifications/NotificationQueueingTest.php
git commit -m "feat(notifications): notify recipients without leaking internal notes" --trailer "Refs: T10.2"
```

---

### T10.3 — Inbox endpoints and the unread count

- [ ] **Step 1: Write the failing test** `tests/Feature/Notifications/NotificationEndpointsTest.php`

```php
<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\TicketAssignedNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->me = User::factory()->agent()->create();
    $this->someoneElse = User::factory()->agent()->create();

    $this->store = fn (User $user, bool $read = false, string $title = 'Assigned'): DatabaseNotification => $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => TicketAssignedNotification::class,
        'data' => [
            'ticket_number' => 'TKT-2026-000001',
            'title' => $title,
            'body' => 'You have a new ticket.',
            'url' => url('/tickets/TKT-2026-000001'),
        ],
        'read_at' => $read ? now() : null,
    ]);
});

it('lists only my notifications, newest first, paginated (API)', function (): void {
    ($this->store)($this->me, title: 'older');
    $this->travel(1)->minutes();
    ($this->store)($this->me, read: true, title: 'newer');
    ($this->store)($this->someoneElse, title: 'not mine');
    Sanctum::actingAs($this->me, ['*']);

    $this->getJson(route('api.v1.notifications.index', ['per_page' => 1]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'newer')
        ->assertJsonPath('data.0.ticket_number', 'TKT-2026-000001')
        ->assertJsonPath('data.0.type', 'TicketAssignedNotification')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonMissing(['title' => 'not mine']);
});

it('filters to unread notifications', function (): void {
    ($this->store)($this->me, title: 'unread one');
    ($this->store)($this->me, read: true, title: 'read one');
    Sanctum::actingAs($this->me, ['*']);

    $this->getJson(route('api.v1.notifications.index', ['unread' => 1]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'unread one');
});

it('rejects per_page above 100', function (): void {
    Sanctum::actingAs($this->me, ['*']);

    $this->getJson(route('api.v1.notifications.index', ['per_page' => 101]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('per_page');
});

it('renders the inbox page', function (): void {
    ($this->store)($this->me);
    ($this->store)($this->someoneElse);

    $this->actingAs($this->me)
        ->get(route('notifications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('notifications/Index')
            ->has('items.data', 1)
            ->where('unreadOnly', false)
            ->has('notifications.unread_count'));
});

it('marks my notification as read (API and web)', function (): void {
    $apiOne = ($this->store)($this->me);
    $webOne = ($this->store)($this->me);

    Sanctum::actingAs($this->me, ['*']);
    $this->postJson(route('api.v1.notifications.read', $apiOne->id))
        ->assertOk()
        ->assertJsonPath('data.id', $apiOne->id);
    expect($apiOne->fresh()->read_at)->not->toBeNull();

    $this->actingAs($this->me)
        ->from(route('notifications.index'))
        ->post(route('notifications.read', $webOne->id))
        ->assertRedirect(route('notifications.index'));
    expect($webOne->fresh()->read_at)->not->toBeNull();
});

it('cannot mark another user\'s notification as read', function (): void {
    $theirs = ($this->store)($this->someoneElse);

    Sanctum::actingAs($this->me, ['*']);
    $this->postJson(route('api.v1.notifications.read', $theirs->id))->assertNotFound();

    $this->actingAs($this->me)->post(route('notifications.read', $theirs->id))->assertNotFound();

    expect($theirs->fresh()->read_at)->toBeNull();
});

it('returns 404 for a malformed id', function (): void {
    Sanctum::actingAs($this->me, ['*']);

    $this->postJson('/api/v1/notifications/not-a-uuid/read')->assertNotFound();
});

it('marks all of my notifications as read and leaves others alone', function (): void {
    ($this->store)($this->me);
    ($this->store)($this->me);
    $theirs = ($this->store)($this->someoneElse);

    Sanctum::actingAs($this->me, ['*']);
    $this->postJson(route('api.v1.notifications.read-all'))->assertNoContent();

    expect($this->me->unreadNotifications()->count())->toBe(0)
        ->and($theirs->fresh()->read_at)->toBeNull();

    ($this->store)($this->me);
    $this->actingAs($this->me)
        ->from(route('notifications.index'))
        ->post(route('notifications.read-all'))
        ->assertRedirect(route('notifications.index'))
        ->assertSessionHas('success');
    expect($this->me->unreadNotifications()->count())->toBe(0);
});

it('shares my unread count with every Inertia page', function (): void {
    ($this->store)($this->me);
    ($this->store)($this->me);
    ($this->store)($this->me, read: true);
    ($this->store)($this->someoneElse);

    $this->actingAs($this->me)
        ->get(route('notifications.index'))
        ->assertInertia(fn (Assert $page) => $page->where('notifications.unread_count', 2));
});
```

- [ ] **Step 2: Write the failing test** `tests/Feature/Authorization/NotificationAuthorizationTest.php` (T04's `AuthorizationMatrix`: `/api/` URIs are sent as JSON with Sanctum, others through the web session)

```php
<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use App\Notifications\TicketAssignedNotification;
use Illuminate\Support\Str;
use Tests\Support\AuthorizationMatrix;

/*
 * `{own}` in a URI is replaced by the id of a notification owned by the acting user
 * (for guests, by some other user's notification). Ownership across users is covered in NotificationEndpointsTest.
 */
it('enforces the notifications matrix', function (?Role $role, string $method, string $uri, int $status): void {
    $matrix = AuthorizationMatrix::for($this);
    $owner = $role === null ? User::factory()->customer()->create() : $matrix->actingAsRole($role);
    if ($role === null) {
        $matrix->asGuest();
    }

    $notificationId = $owner->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => TicketAssignedNotification::class,
        'data' => ['ticket_number' => null, 'title' => 't', 'body' => 'b', 'url' => url('/')],
    ])->id;

    $matrix->expectAccess($method, str_replace('{own}', $notificationId, $uri), $status);
})->with(function (): array {
    $routes = [
        // [method, uri, status when signed in, status as guest]
        ['GET', '/notifications', 200, 302],
        ['POST', '/notifications/{own}/read', 302, 302],
        ['POST', '/notifications/read-all', 302, 302],
        ['GET', '/api/v1/notifications', 200, 401],
        ['POST', '/api/v1/notifications/{own}/read', 200, 401],
        ['POST', '/api/v1/notifications/read-all', 204, 401],
    ];

    $rows = [];
    foreach ($routes as [$method, $uri, $signedIn, $guest]) {
        $rows["guest · {$method} {$uri} → {$guest}"] = [null, $method, $uri, $guest];
        foreach ([Role::Customer, Role::Agent, Role::Admin] as $role) {
            $rows["{$role->value} · {$method} {$uri} → {$signedIn}"] = [$role, $method, $uri, $signedIn];
        }
    }

    return $rows;
});
```

- [ ] **Step 3: Run them, expect FAIL**

```bash
./vendor/bin/pest tests/Feature/Notifications/NotificationEndpointsTest.php tests/Feature/Authorization/NotificationAuthorizationTest.php
```
Expected: FAIL with `RouteNotFoundException: Route [api.v1.notifications.index] not defined.`; the matrix rows fail with `Expected response status code [200] but received 404.`

- [ ] **Step 4: Implement** `app/Actions/Notifications/MarkNotificationRead.php`

```php
<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

final class MarkNotificationRead
{
    public function handle(User $user, string $notificationId): DatabaseNotification
    {
        // Looked up through the owner's relation: another user's id is simply not found (404) and never touched.
        /** @var DatabaseNotification $notification */
        $notification = $user->notifications()->whereKey($notificationId)->firstOrFail();

        $notification->markAsRead();

        return $notification;
    }
}
```

- [ ] **Step 5: Implement** `app/Actions/Notifications/MarkAllNotificationsRead.php`

```php
<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Models\User;

final class MarkAllNotificationsRead
{
    /**
     * @return int number of notifications marked
     */
    public function handle(User $user): int
    {
        // A single UPDATE scoped to the owner, served by the (notifiable_type, notifiable_id, read_at) index.
        return $user->unreadNotifications()->update(['read_at' => now()]);
    }
}
```

- [ ] **Step 6: Implement** `app/Services/Notifications/NotificationFeed.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Notifications\DatabaseNotification;

final class NotificationFeed
{
    /**
     * @return LengthAwarePaginator<int, DatabaseNotification>
     */
    public function for(User $user, int $perPage, bool $unreadOnly): LengthAwarePaginator
    {
        $relation = $unreadOnly ? $user->unreadNotifications() : $user->notifications();

        // The relation already orders newest first.
        return $relation
            ->select(['id', 'type', 'data', 'read_at', 'created_at'])
            ->paginate($perPage)
            ->withQueryString();
    }
}
```

- [ ] **Step 7: Implement** `app/Http/Requests/IndexNotificationsRequest.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class IndexNotificationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Every query is scoped to the user's own notifications, so being signed in is the whole rule.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'unread' => ['sometimes', 'boolean'],
        ];
    }

    public function perPage(): int
    {
        return $this->integer('per_page', 20);
    }

    public function unreadOnly(): bool
    {
        return $this->boolean('unread');
    }

    public function actor(): User
    {
        $user = $this->user();
        assert($user instanceof User);

        return $user;
    }
}
```

- [ ] **Step 8: Implement** `app/Http/Requests/MarkNotificationReadRequest.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class MarkNotificationReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Ownership is enforced by the Actions, which only ever see the user's own notifications.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    public function actor(): User
    {
        $user = $this->user();
        assert($user instanceof User);

        return $user;
    }
}
```

- [ ] **Step 9: Implement** `app/Http/Resources/V1/NotificationResource.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * @mixin DatabaseNotification
 */
final class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DatabaseNotification $notification */
        $notification = $this->resource;
        /** @var array<string, mixed> $data */
        $data = $notification->data;

        return [
            'id' => $notification->id,
            'type' => class_basename($notification->type),
            'ticket_number' => $data['ticket_number'] ?? null,
            'title' => $data['title'] ?? '',
            'body' => $data['body'] ?? '',
            'url' => $data['url'] ?? null,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 10: Implement** `app/Http/Controllers/Web/NotificationController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Notifications\MarkAllNotificationsRead;
use App\Actions\Notifications\MarkNotificationRead;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexNotificationsRequest;
use App\Http\Requests\MarkNotificationReadRequest;
use App\Http\Resources\V1\NotificationResource;
use App\Services\Notifications\NotificationFeed;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class NotificationController extends Controller
{
    public function index(IndexNotificationsRequest $request, NotificationFeed $feed): Response
    {
        return Inertia::render('notifications/Index', [
            // Not named `notifications`: that key is the shared {unread_count} prop and a page prop would shadow it.
            'items' => NotificationResource::collection(
                $feed->for($request->actor(), $request->perPage(), $request->unreadOnly()),
            ),
            'unreadOnly' => $request->unreadOnly(),
        ]);
    }

    public function read(MarkNotificationReadRequest $request, string $notification, MarkNotificationRead $markRead): RedirectResponse
    {
        $markRead->handle($request->actor(), $notification);

        return back();
    }

    public function readAll(MarkNotificationReadRequest $request, MarkAllNotificationsRead $markAllRead): RedirectResponse
    {
        $count = $markAllRead->handle($request->actor());

        return back()->with('success', $count === 1 ? '1 notification marked as read.' : sprintf('%d notifications marked as read.', $count));
    }
}
```

- [ ] **Step 11: Implement** `app/Http/Controllers/Api/V1/NotificationController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Notifications\MarkAllNotificationsRead;
use App\Actions\Notifications\MarkNotificationRead;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexNotificationsRequest;
use App\Http\Requests\MarkNotificationReadRequest;
use App\Http\Resources\V1\NotificationResource;
use App\Services\Notifications\NotificationFeed;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class NotificationController extends Controller
{
    public function index(IndexNotificationsRequest $request, NotificationFeed $feed): AnonymousResourceCollection
    {
        return NotificationResource::collection(
            $feed->for($request->actor(), $request->perPage(), $request->unreadOnly()),
        );
    }

    public function read(MarkNotificationReadRequest $request, string $notification, MarkNotificationRead $markRead): NotificationResource
    {
        return NotificationResource::make($markRead->handle($request->actor(), $notification));
    }

    public function readAll(MarkNotificationReadRequest $request, MarkAllNotificationsRead $markAllRead): Response
    {
        $markAllRead->handle($request->actor());

        return response()->noContent();
    }
}
```

- [ ] **Step 12: Create** `routes/web/notifications.php`

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\Web\NotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])
        ->whereUuid('notification')
        ->name('notifications.read');
});
```

- [ ] **Step 13: Create** `routes/api/v1/notifications.php`

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\NotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])
        ->whereUuid('notification')
        ->name('notifications.read');
});
```

- [ ] **Step 14: Modify** `app/Http/Middleware/HandleInertiaRequests.php`. In the array returned by `share()`, replace **only the value** of the `'notifications'` key (T00's placeholder returns `unread_count` 0) with the closure below. Leave every other key, and the method signature, untouched. If `Illuminate\Http\Request` isn't already imported (it is in the starter kit), don't add anything else.

```php
            // Lazy: only evaluated when the page is rendered, and skipped on partial reloads that don't ask for it.
            'notifications' => fn (): array => [
                'unread_count' => $request->user()?->unreadNotifications()->count() ?? 0,
            ],
```

- [ ] **Step 15: Run them, expect PASS**

```bash
./vendor/bin/pest tests/Feature/Notifications/NotificationEndpointsTest.php tests/Feature/Authorization/NotificationAuthorizationTest.php
```
Expected: PASS: `NotificationEndpointsTest` 9 tests, `NotificationAuthorizationTest` 24 tests. (`renders the inbox page` needs the page file from T10.4. Until it exists, Inertia's `ensure_pages_exist` check makes that one test fail with `Inertia page component file [notifications/Index] does not exist.` If T10.4 isn't in place yet, create T10.4's `Index.vue` first; it has no dependencies.)

- [ ] **Step 16: Confirm the routes**

```bash
php artisan route:list --name=notifications
```
Expected: six routes. `notifications.index`, `notifications.read` and `notifications.read-all` exist for the web and again with the `api.v1.` prefix; both `read` routes have the uuid constraint.

- [ ] **Step 17: Style and static analysis**

```bash
./vendor/bin/pint app routes tests && ./vendor/bin/phpstan analyse --memory-limit=1G
```
Expected: Pint clean; PHPStan `[OK] No errors`.

- [ ] **Step 18: Commit**

```bash
git add app/Actions/Notifications app/Services/Notifications/NotificationFeed.php \
  app/Http/Requests/IndexNotificationsRequest.php app/Http/Requests/MarkNotificationReadRequest.php \
  app/Http/Resources/V1/NotificationResource.php app/Http/Controllers/Web/NotificationController.php \
  app/Http/Controllers/Api/V1/NotificationController.php routes/web/notifications.php routes/api/v1/notifications.php \
  app/Http/Middleware/HandleInertiaRequests.php tests/Feature/Notifications/NotificationEndpointsTest.php \
  tests/Feature/Authorization/NotificationAuthorizationTest.php resources/js/pages/notifications/Index.vue
git commit -m "feat(notifications): add inbox endpoints with owner-scoped mark-read" --trailer "Refs: T10.3"
```
(Drop `resources/js/pages/notifications/Index.vue` from the `git add` if T10.4 commits it.)

---

### T10.4 — Bell, navigation module and inbox page

- [ ] **Step 1: Baseline type check**

```bash
npx vue-tsc --noEmit
```
Expected: PASS (exit 0).

- [ ] **Step 2: Create** `resources/js/types/notifications.ts`

```ts
export type NotificationItem = {
    id: string;
    type: string;
    ticket_number: string | null;
    title: string;
    body: string;
    url: string | null;
    read_at: string | null;
    created_at: string | null;
};

/** Laravel's paginated resource collection shape, as the Inertia prop receives it. */
export type NotificationPage = {
    data: NotificationItem[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
};
```

- [ ] **Step 3: Create** `resources/js/components/notifications/NotificationBell.vue`

```vue
<script setup lang="ts">
import type { SharedProps } from '@/types/shared';
import { usePage } from '@inertiajs/vue3';
import { Bell } from '@lucide/vue';
import { computed } from 'vue';

// No props: T00's sidebar renders NavItem icons as `<component :is="item.icon" />`, so the bell must stand alone.
const page = usePage<SharedProps>();

const unread = computed(() => page.props.notifications.unread_count);

const badge = computed(() =>
    unread.value > 99 ? '99+' : String(unread.value),
);
</script>

<template>
    <span class="relative inline-flex size-4 shrink-0">
        <Bell class="size-full" aria-hidden="true" />
        <span
            v-if="unread > 0"
            class="absolute -top-1.5 -right-2 min-w-4 rounded-full bg-red-600 px-1 text-center text-[10px] leading-4 font-semibold text-white"
            role="status"
            :aria-label="`${unread} unread notifications`"
        >
            {{ badge }}
        </span>
    </span>
</template>
```

- [ ] **Step 4: Create** `resources/js/config/navigation/notifications.ts`

```ts
import NotificationBell from '@/components/notifications/NotificationBell.vue';
import type { NavItem } from '@/config/navigation';

// T00's AppSidebar renders `<component :is="item.icon" v-if="item.icon" />` for each NavItem, so the bell
// (with its unread badge) appears in the sidebar, collapsed or not, without editing a T00 file.
const items: NavItem[] = [
    {
        title: 'Notifications',
        href: '/notifications',
        icon: NotificationBell,
        order: 90,
        visible: () => true,
    },
];

export default items;
```

- [ ] **Step 5: Create** `resources/js/pages/notifications/Index.vue`

```vue
<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { NotificationItem, NotificationPage } from '@/types/notifications';
import { Head, Link, router } from '@inertiajs/vue3';
import { Bell, CheckCheck } from '@lucide/vue';
import { computed } from 'vue';

const props = defineProps<{
    items: NotificationPage;
    unreadOnly: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Notifications', href: '/notifications' }],
    },
});

const hasUnread = computed(() =>
    props.items.data.some((item) => item.read_at === null),
);

function formatDate(value: string | null): string {
    return value === null ? '' : new Date(value).toLocaleString();
}

function markRead(item: NotificationItem): void {
    router.post(
        `/notifications/${item.id}/read`,
        {},
        { preserveScroll: true, preserveState: true },
    );
}

function open(item: NotificationItem): void {
    const go = (): void => {
        if (item.url !== null) {
            router.visit(item.url);
        }
    };

    if (item.read_at === null) {
        router.post(
            `/notifications/${item.id}/read`,
            {},
            { preserveScroll: true, onSuccess: go },
        );
    } else {
        go();
    }
}

function markAll(): void {
    router.post('/notifications/read-all', {}, { preserveScroll: true });
}

function toggleUnread(): void {
    router.get('/notifications', props.unreadOnly ? {} : { unread: 1 }, {
        preserveState: true,
        replace: true,
    });
}
</script>

<template>
    <Head title="Notifications" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-4 p-4">
        <Card>
            <CardHeader
                class="flex flex-row flex-wrap items-center justify-between gap-2"
            >
                <CardTitle class="flex items-center gap-2 text-base">
                    <Bell class="size-4" aria-hidden="true" />
                    Notifications
                </CardTitle>
                <div class="flex gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :aria-pressed="unreadOnly"
                        @click="toggleUnread"
                    >
                        {{ unreadOnly ? 'Show all' : 'Unread only' }}
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        :disabled="!hasUnread"
                        @click="markAll"
                    >
                        <CheckCheck class="size-4" aria-hidden="true" />
                        Mark all read
                    </Button>
                </div>
            </CardHeader>

            <CardContent>
                <p
                    v-if="items.data.length === 0"
                    class="py-8 text-center text-sm text-muted-foreground"
                >
                    {{
                        unreadOnly
                            ? 'No unread notifications.'
                            : 'No notifications yet.'
                    }}
                </p>

                <ul v-else class="divide-y divide-border">
                    <li
                        v-for="item in items.data"
                        :key="item.id"
                        class="flex items-start gap-3 py-3"
                    >
                        <span
                            :class="[
                                'mt-1.5 size-2 shrink-0 rounded-full',
                                item.read_at === null
                                    ? 'bg-primary'
                                    : 'bg-transparent',
                            ]"
                            aria-hidden="true"
                        />
                        <button
                            type="button"
                            class="min-w-0 flex-1 text-left"
                            @click="open(item)"
                        >
                            <span
                                :class="[
                                    'block text-sm',
                                    item.read_at === null
                                        ? 'font-semibold'
                                        : 'font-normal',
                                ]"
                                >{{ item.title }}</span
                            >
                            <span
                                class="block truncate text-sm text-muted-foreground"
                                >{{ item.body }}</span
                            >
                            <time
                                :datetime="item.created_at ?? undefined"
                                class="block text-xs text-muted-foreground"
                            >
                                {{ formatDate(item.created_at) }}
                            </time>
                        </button>
                        <Button
                            v-if="item.read_at === null"
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="markRead(item)"
                            >Mark read</Button
                        >
                    </li>
                </ul>

                <nav
                    v-if="items.meta.last_page > 1"
                    class="mt-4 flex items-center justify-between text-sm"
                    aria-label="Notification pages"
                >
                    <Link
                        v-if="items.links.prev"
                        :href="items.links.prev"
                        preserve-scroll
                        class="underline"
                        >Previous</Link
                    >
                    <span v-else />
                    <span class="text-muted-foreground"
                        >Page {{ items.meta.current_page }} of
                        {{ items.meta.last_page }}</span
                    >
                    <Link
                        v-if="items.links.next"
                        :href="items.links.next"
                        preserve-scroll
                        class="underline"
                        >Next</Link
                    >
                    <span v-else />
                </nav>
            </CardContent>
        </Card>
    </div>
</template>
```

- [ ] **Step 6: Type check and build, expect PASS**

```bash
npm run build && npm run check && npx vue-tsc --noEmit
```
Expected: `vue-tsc` exits 0; Vite prints `✓ built in …`. `NavItem` comes from `@/config/navigation`, which re-exports it from `@/types/navigation` (T00). `SharedProps.notifications.unread_count` is typed by T00 in `@/types/shared`.

- [ ] **Step 7: Run the page test, expect PASS**

```bash
./vendor/bin/pest tests/Feature/Notifications/NotificationEndpointsTest.php --filter="renders the inbox page"
```
Expected: PASS.

- [ ] **Step 8: Manual smoke run** (`composer run dev` plus `php artisan queue:work --queue=notifications,default`)
  - As `admin@example.com`, assign a ticket to `agent1@example.com`.
  - As `agent1`: the *Notifications* nav item shows a red `1`. Opening the inbox shows the item in bold; clicking it opens the ticket, and the badge clears.
  - Mailpit (Sail) or `storage/logs/laravel.log` (log mailer) shows the "assigned to you" mail with a *View ticket* link.

- [ ] **Step 9: Commit**

```bash
git add resources/js/types/notifications.ts resources/js/components/notifications/NotificationBell.vue \
  resources/js/config/navigation/notifications.ts resources/js/pages/notifications/Index.vue
git commit -m "feat(notifications): add navigation bell and inbox page" --trailer "Refs: T10.4"
```

---

## 6. Acceptance criteria

- [ ] All eight notifications in README §3.9 exist, implement `ShouldQueue`, use queue `notifications` and set `afterCommit`. Their database payload is exactly `{ticket_number, title, body, url}`.
- [ ] Ticket notifications send mail with a `View ticket` action pointing to `route('tickets.show', $ticket)`. `InternalNoteAddedNotification` and `TicketImportFinishedNotification` use only the `database` channel.
- [ ] A real event dispatch pushes `SendQueuedNotifications` jobs onto the `notifications` queue, never `default`.
- [ ] Recipients match spec §11 for every event (dataset tests). Each recipient is notified once, even when they qualify twice (for example an admin who is also the assignee on a breach).
- [ ] No listener notifies the event's actor or reply author. The only exception is the creation confirmation to a self-service customer, which is documented and tested.
- [ ] Internal notes: the customer receives nothing, through any notification class, on any channel. This is proven at the listener level and end to end through the reply endpoint. `TicketRepliedNotification` refuses internal replies.
- [ ] Imported tickets (`TicketCreated(imported: true)`) notify nobody; the importing admin gets one `TicketImportFinishedNotification`.
- [ ] `GET notifications` (web/API) lists only the caller's notifications, newest first, paginated (≤ 100), with an `unread` filter. `read` and `read-all` touch only the caller's rows; another user's id → 404.
- [ ] `notifications.unread_count` is shared lazily by the single closure T10 replaced in `HandleInertiaRequests`.
- [ ] The bell is mounted through `navigation/notifications.ts` (T00's registry). No T00-owned file other than that closure is edited. No `v-html`.

## 7. Verification

```bash
./vendor/bin/pint --test
./vendor/bin/phpstan analyse --memory-limit=1G
./vendor/bin/pest --parallel
npm run build && npx vue-tsc --noEmit
php artisan event:list | grep -c -E "Send(Ticket|Sla|Import)"
```
Expected: Pint shows no changes; PHPStan `[OK] No errors`; Pest is all green, including the T10 files (`NotificationQueueingTest` 26, `NotificationRecipientsTest` 33, `InternalNoteLeakageTest` 5, `NotificationEndpointsTest` 9, `NotificationAuthorizationTest` 24); the build and `vue-tsc` exit 0; the `event:list` count prints `7`.

## Contract notes for controller

1. Resolved by the controller's decision: the customer's creation confirmation is the single documented exception to "the actor is never notified". It's isolated in `SendTicketCreatedNotifications` and has its own test.
2. Resolved by the controller's decision: on `TicketCreated`, admins are notified only when `config('tickets.auto_assign')` is false. When auto-assignment finds no agent, the ticket surfaces in the unassigned queue and on the dashboard, not through a notification. No new event is added.
3. Resolved: the bell conforms to T00's `NavItem` (`@/config/navigation`, re-exported from `@/types/navigation`) and is mounted as the `icon` of `navigation/notifications.ts`. T00's `AppSidebar` renders it as `<component :is="item.icon" />`, and T00 offers no header slot. `NotificationBell` reads `SharedProps.notifications.unread_count`, which T00 types. Since it has no props, it can move into a header slot later without changes.
4. `admin.imports.show` belongs to T11, which runs in parallel in wave 4b. `TicketImportFinishedNotification` uses `url('/admin/imports/{id}')` instead of `route()`, so T10 doesn't depend on T11's route existing. The path must match T11's route.
5. The notification routes have no Policy. `DatabaseNotification` is a framework model, and registering a policy would need `AppServiceProvider` (owned by T00/T04). Ownership is enforced by construction instead: the Actions and `NotificationFeed` only ever query `$user->notifications()`. The matrix test and the cross-user test prove it. If a Policy is required, T04 would add `Gate::policy(DatabaseNotification::class, NotificationPolicy::class)`.
6. In T00's `HandleInertiaRequests::share()`, the `notifications` key holds a placeholder. T10 replaces only that key's value, with the closure in T10.3 Step 14.
7. Following the events (README §3.6), the notifications expose `public readonly` constructor properties and rely on `SerializesModels`, which supports readonly properties.
8. The inbox page prop is named `items`, not `notifications`. Inertia merges page props over shared props shallowly, so a page prop called `notifications` would replace the shared `notifications.unread_count` and blank the bell on that page. The endpoint test asserts that both are present.
9. Resolved: the matrix uses T04's `AuthorizationMatrix::for($this)` with `actingAsRole()`, `asGuest()` and `expectAccess()`.
10. Laravel 13: queued notifications now honour `#[DeleteWhenMissingModels]` (and `$deleteWhenMissingModels`) on the notification class (`SendQueuedNotifications::__construct`). These notifications don't use it: the product flow closes tickets instead of deleting them, and users with tickets can't be deleted (TD-025), so a model missing when the job runs is a real fault and should fail the job visibly, not vanish. The queue name and `afterCommit` still reach the job from the `Queueable` properties set in `BaseNotification::__construct` (`NotificationSender::queueNotification` reads the `queue` property; `SendQueuedNotifications` copies `afterCommit`). Laravel 13's new default password-reset subject ("Reset your password") doesn't affect T10: no test asserts it.
