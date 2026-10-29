# T04: Authorization (Policies, Gate, shared auth props, matrix helper)

> **For agentic workers:** REQUIRED SUB-SKILL: use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax. Work test-first. Read `CLAUDE.md` (Security rules), the spec (§2) and README §3.11, §3.13 and §4 before starting.

## 1. Header

- **Objective:** Implement every Policy in README §3.11 by combining spatie permissions with ownership, register the `viewDashboard` Gate, share `auth.user.roles` and `auth.can` through Inertia, and provide the reusable `AuthorizationMatrix` test helper with a full policy-level ticket matrix.
- **Depends on:** T01 (enums `Role`, `Permission`, `TicketStatus`; models; factories with role states; `RolesAndPermissionsSeeder`; `tests/Pest.php`). T00 (`AppServiceProvider`, `HandleInertiaRequests`).
- **Wave:** 2 (parallel with T02 and T03).
- **TDs implemented:** TD-010 (spatie capabilities + Policies as the only entry point), TD-011 (permission matrix), TD-012 (404 instead of 403 for tickets a customer can't view), TD-024 (attachment `is_internal` flag in authorization), TD-120 (server-side authorization everywhere; explicit Inertia props).

### Design notes (read before coding)

**Two layers, one entry point (TD-010).** Each policy method asks spatie *what kind of thing* the user may do (`$user->can(Permission::X->value)`, which resolves through spatie's `Gate::before`), then checks ownership *on this record*. No policy calls `hasRole()`, and no string literal names a role or permission.

**Visibility first, then the action (TD-012).** Every ticket-scoped ability starts with "may this user see the ticket?". If not, the result is `Response::denyAsNotFound()` (404). Only a user who can see the ticket gets a plain `Response::deny()` (403) for an action they may not take. Customers therefore can't confirm a ticket exists through *any* route, not only `tickets.show`. This holds even before T05 scopes route-model binding through `Ticket::visibleTo()`. The same applies to replies and attachments: an internal reply or attachment is reported to a customer as not found.

**Who may try versus whether it is valid.** Policies decide *who may attempt* a change. Rules about the ticket's data belong to the Actions: transition validity (`InvalidStatusTransition`, 422), the target agent's eligibility (`AgentNotEligible`, 422), and the claim race (`TicketAlreadyClaimed`, 409). Two consequences:
- `claim` does **not** check `assigned_agent_id`. If it did, an agent who lost the race by a few milliseconds would get 403 instead of the contracted 409 (TD-051).
- `changeStatus` for staff does not look at `$to`. For customers, `$to` is the special case in README §3.11: on their own `Resolved` ticket a customer may move to `Closed` or `InProgress` (reopen) only. With `$to = null`, the policy answers "may this user change the status at all?", which is what the UI's `can.changeStatus` needs.

**Closed tickets.** `reply` and `addInternalNote` are denied (403) on `Closed` tickets for everyone, so `can.reply` is false in the UI and the API returns 403. `AddReply`'s `TicketClosed` exception (T07) stays as the domain-level guard for direct Action calls.

**The matrix helper is an object, not a trait.** Pest datasets are built when the file loads, through static methods, and calling a static method on a trait directly is deprecated in PHP 8.3. So `Tests\Support\AuthorizationMatrix` is a final class: `AuthorizationMatrix::for($this)` wraps the running test case for HTTP calls, and its static methods build datasets. `Tests\Support\TicketRelation` names the ownership setups.

## 2. Files

**Create**
- `app/Policies/TicketPolicy.php`
- `app/Policies/TicketReplyPolicy.php`
- `app/Policies/AttachmentPolicy.php`
- `app/Policies/CategoryPolicy.php`
- `app/Policies/HolidayPolicy.php`
- `app/Policies/UserPolicy.php`
- `app/Policies/TicketImportPolicy.php`
- `app/Policies/DashboardPolicy.php`
- `tests/Support/AuthorizationMatrix.php`
- `tests/Support/TicketRelation.php`
- `tests/Feature/Authorization/AuthorizationMatrixTest.php`
- `tests/Feature/Authorization/TicketPolicyTest.php`
- `tests/Feature/Authorization/TicketReplyPolicyTest.php`
- `tests/Feature/Authorization/AttachmentPolicyTest.php`
- `tests/Feature/Authorization/AdminPoliciesTest.php`
- `tests/Feature/Authorization/DashboardGateTest.php`
- `tests/Feature/Authorization/SharedAuthPropsTest.php`

**Modify**
- `app/Providers/AppServiceProvider.php`: add the `viewDashboard` Gate in `boot()` (README §4).
- `app/Http/Middleware/HandleInertiaRequests.php`: replace the `auth` entry only (README §4). Do not touch the `notifications` closure (T10) or any other key.

**Must not touch**
- `app/Enums/*`, `app/Models/*`, `database/**` (T01). Missing permissions go to the controller: stop and report.
- `bootstrap/app.php`, `routes/**` (no routes in this task), `resources/js/**` (T00 owns the `SharedProps` TypeScript type).
- `tests/Pest.php`, `tests/TestCase.php`.

## 3. Interfaces

### Consumes
- `App\Enums\Role` (`Admin`, `Agent`, `Customer`) and `App\Enums\Permission` (README §3.1). T01.
- The seeded baseline `Permission::forRole()` (TD-011), which this task's tests rely on:
  - **customer:** `TicketsCreate`, `TicketsViewOwn`, `TicketsReply`, `TicketsUpdateOwn`
  - **agent:** `TicketsCreate`, `TicketsCreateOnBehalf`, `TicketsViewAny`, `TicketsReply`, `TicketsNotesInternal`, `TicketsClaim`, `TicketsUpdateAssigned`, `TicketsHandoffOwn`, `DashboardView`
  - **admin:** every `Permission` case
- `App\Enums\TicketStatus` with `isActive()`. T01.
- Models `User` (`HasRoles`), `Ticket` (`customer_id`, `assigned_agent_id`, `status`), `TicketReply` (`ticket()`, `is_internal`), `Attachment` (`ticket()`, `is_internal`), `Category`, `Holiday`, `TicketImport`. T01.
- Factories `UserFactory::admin()/agent()/customer()` (roles assigned in `afterCreating`), `TicketFactory::assignedTo(User)`, `TicketReplyFactory::internal()`, `AttachmentFactory`. T01.
- `tests/Pest.php`: `Feature` tests extend `Tests\TestCase` with `RefreshDatabase`, seed `RolesAndPermissionsSeeder` and forget the permission cache in `beforeEach`. T01.

### Produces

Every ticket-scoped method returns `Illuminate\Auth\Access\Response` (README §3.11): allow, deny (403), or `denyAsNotFound` (404) when the user can't see the ticket. `AttachmentPolicy::view` is the download check, and `UserPolicy::delete` denies deleting oneself.

```php
final class TicketPolicy {
    public function viewAny(User $user): bool;
    public function view(User $user, Ticket $ticket): Response;
    public function create(User $user): bool;
    public function createOnBehalf(User $user): bool;
    public function update(User $user, Ticket $ticket): Response;
    public function changeStatus(User $user, Ticket $ticket, ?TicketStatus $to = null): Response;
    public function changePriority(User $user, Ticket $ticket): Response;
    public function claim(User $user, Ticket $ticket): Response;
    public function assign(User $user, Ticket $ticket): Response;
    public function reply(User $user, Ticket $ticket): Response;
    public function addInternalNote(User $user, Ticket $ticket): Response;
    public function viewInternal(User $user, Ticket $ticket): Response;
}
final class TicketReplyPolicy { public function view(User $user, TicketReply $reply): Response; }
final class AttachmentPolicy  { public function view(User $user, Attachment $attachment): Response; }
final class CategoryPolicy    { viewAny(User): bool; create(User): bool; update(User, Category): bool; delete(User, Category): bool; }
final class HolidayPolicy     { viewAny(User): bool; create(User): bool; update(User, Holiday): bool; delete(User, Holiday): bool; }
final class UserPolicy        { viewAny(User): bool; create(User): bool; update(User, User): bool; delete(User, User): bool; }
final class TicketImportPolicy{ viewAny(User): bool; view(User, TicketImport): bool; create(User): bool; update(User, TicketImport): bool; delete(User, TicketImport): bool; }
final class DashboardPolicy   { public function view(User $user): bool; }
```

The `viewDashboard` Gate is defined in `AppServiceProvider::boot()` as `Gate::define('viewDashboard', [DashboardPolicy::class, 'view'])`.

Usage from later tasks: `$this->authorize('changeStatus', [$ticket, $to])`, `Gate::authorize('view', $attachment)`, `$user->can('reply', $ticket)`, `$request->user()->can('viewDashboard')`.

**Shared Inertia props** (README §3.13), produced by `HandleInertiaRequests::share()`:

```php
'auth' => [
    'user' => null | ['id' => int, 'name' => string, 'email' => string, 'email_verified_at' => ?string, 'roles' => list<string>],
    'can'  => ['viewDashboard' => bool, 'manageImports' => bool, 'manageUsers' => bool,
               'manageCategories' => bool, 'manageHolidays' => bool, 'createOnBehalf' => bool],
],
```

**Test support** (README §4; one file per class):

```php
namespace Tests\Support;

enum TicketRelation: string { case Own = 'own'; case Assigned = 'assigned'; case Unassigned = 'unassigned'; case Others = 'others'; }

final class AuthorizationMatrix {
    public static function for(\Tests\TestCase $test): self;
    public function actingAsRole(Role $role, array $attributes = []): User;
    public function asGuest(): self;
    public function user(): User;
    public function ticketFor(TicketRelation $relation, array $attributes = []): Ticket;
    public function expectAccess(string $method, string $uri, int $status, array $payload = []): TestResponse;
    public function expectAccessAs(Role $role, string $method, string|\Closure $uri, int $status, array $payload = [],
                                   ?TicketRelation $relation = null, array $ticketAttributes = []): TestResponse;
    public static function statusOf(Response $response): int;
    /** @return array<string, array{0: Role}> */
    public static function roles(): array;
    public static function ticketCases(array $rows): array;
}
```

`actingAsRole()` creates the user and later HTTP calls act as them. `ticketFor()` is the ownership setup hook: it creates a ticket related to the acting user as described by the `TicketRelation`, with factory overrides in `$attributes`. `statusOf()` maps a `Response` to 200 (allowed) or 403/404. `ticketCases()` builds a labelled dataset, labelling each row "role · relation · status · ability(args) → status" for readable Pest output. `expectAccessAs()` accepts a closure for `$uri`, which receives the ticket built by `ticketFor()`. The helper is used at policy level in T04 and at HTTP level by each later task's `tests/Feature/Authorization/<Feature>AuthorizationTest.php` (TD-010, TD-011, TD-012). Example at policy level: `AuthorizationMatrix::statusOf(Gate::forUser($user)->inspect('update', $ticket))` should be `200`. Example at HTTP level: `AuthorizationMatrix::for($this)->expectAccessAs(Role::Customer, 'GET', fn (Ticket $t) => route('tickets.show', $t), 404, relation: TicketRelation::Others)`.

URIs whose path starts with `/api/` are sent as JSON with `Sanctum::actingAs($user, ['*'])`. Other URIs go through the `web` guard.

## 4. Subtasks & mini-waves

| Subtask | Files | Depends on | Parallel-safe with |
|---|---|---|---|
| 4.1 Matrix helper | `tests/Support/AuthorizationMatrix.php`, `tests/Support/TicketRelation.php`, `tests/Feature/Authorization/AuthorizationMatrixTest.php` | — | — |
| 4.2 TicketPolicy | `app/Policies/TicketPolicy.php`, `tests/Feature/Authorization/TicketPolicyTest.php` | 4.1 | 4.4 |
| 4.3 Reply + attachment policies | `app/Policies/TicketReplyPolicy.php`, `app/Policies/AttachmentPolicy.php`, `tests/Feature/Authorization/TicketReplyPolicyTest.php`, `tests/Feature/Authorization/AttachmentPolicyTest.php` | 4.2 | 4.5 |
| 4.4 Admin policies + dashboard Gate | `app/Policies/{Category,Holiday,User,TicketImport,Dashboard}Policy.php`, `app/Providers/AppServiceProvider.php`, `tests/Feature/Authorization/AdminPoliciesTest.php`, `tests/Feature/Authorization/DashboardGateTest.php` | 4.1 | 4.2 |
| 4.5 Shared auth props | `app/Http/Middleware/HandleInertiaRequests.php`, `tests/Feature/Authorization/SharedAuthPropsTest.php` | 4.2, 4.4 | 4.3 |

Mini-waves: **A** = 4.1 → **B** = 4.2 ∥ 4.4 → **C** = 4.3 ∥ 4.5.

## 5. Subtasks

### 4.1 AuthorizationMatrix helper

- [ ] **Step 1: Write the failing self-test.** Create `tests/Feature/Authorization/AuthorizationMatrixTest.php`. It uses a test-only Gate ability and test-only routes, so it doesn't depend on any policy or on routes from later tasks. The ability lets owners see their ticket and gives everyone else 404.

```php
<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\Support\AuthorizationMatrix;
use Tests\Support\TicketRelation;

beforeEach(function (): void {
    Gate::define('matrix.view', static fn (User $user, Ticket $ticket): Response => $ticket->customer_id === $user->id
        ? Response::allow()
        : Response::denyAsNotFound());

    $show = static function (Ticket $ticket) {
        Gate::authorize('matrix.view', $ticket);

        return response()->json(['number' => $ticket->number]);
    };

    Route::middleware(['web', 'auth'])->get('/_matrix/tickets/{ticket}', $show);
    Route::middleware(['api', 'auth:sanctum'])->get('/api/_matrix/tickets/{ticket}', $show);
});

it('creates a user with the requested role', function (Role $role): void {
    $user = AuthorizationMatrix::for($this)->actingAsRole($role);

    expect($user->exists)->toBeTrue()
        ->and($user->getRoleNames()->all())->toBe([$role->value]);
})->with(AuthorizationMatrix::roles());

it('builds tickets for every relation', function (): void {
    $matrix = AuthorizationMatrix::for($this);
    $customer = $matrix->actingAsRole(Role::Customer);

    $own = $matrix->ticketFor(TicketRelation::Own, ['status' => TicketStatus::Resolved]);
    expect($own->customer_id)->toBe($customer->id)
        ->and($own->status)->toBe(TicketStatus::Resolved);

    $agent = $matrix->actingAsRole(Role::Agent);
    $assigned = $matrix->ticketFor(TicketRelation::Assigned);
    $unassigned = $matrix->ticketFor(TicketRelation::Unassigned);
    $others = $matrix->ticketFor(TicketRelation::Others);

    expect($assigned->assigned_agent_id)->toBe($agent->id)
        ->and($unassigned->assigned_agent_id)->toBeNull()
        ->and($unassigned->customer_id)->not->toBe($agent->id)
        ->and($others->assigned_agent_id)->not->toBeNull()
        ->and($others->assigned_agent_id)->not->toBe($agent->id)
        ->and($others->customer_id)->not->toBe($agent->id);
});

it('labels dataset rows and rejects duplicates', function (): void {
    $row = [Role::Customer, TicketRelation::Own, TicketStatus::Resolved, 'changeStatus', [TicketStatus::Closed], 200];

    expect(array_keys(AuthorizationMatrix::ticketCases([$row])))
        ->toBe(['customer · own · resolved · changeStatus(closed) → 200'])
        ->and(fn () => AuthorizationMatrix::ticketCases([$row, $row]))
        ->toThrow(LogicException::class, 'Duplicate authorization case');
});

it('checks web routes through the web guard', function (): void {
    $matrix = AuthorizationMatrix::for($this);
    $matrix->actingAsRole(Role::Customer);
    $own = $matrix->ticketFor(TicketRelation::Own);
    $other = $matrix->ticketFor(TicketRelation::Others);

    $matrix->expectAccess('GET', "/_matrix/tickets/{$own->number}", 200)->assertJson(['number' => $own->number]);
    $matrix->expectAccess('GET', "/_matrix/tickets/{$other->number}", 404);
});

it('checks api routes through sanctum as JSON', function (): void {
    $matrix = AuthorizationMatrix::for($this);
    $matrix->actingAsRole(Role::Customer);
    $own = $matrix->ticketFor(TicketRelation::Own);

    $matrix->expectAccess('GET', "/api/_matrix/tickets/{$own->number}", 200)->assertJson(['number' => $own->number]);
});

it('builds the ticket for a URI closure via the ownership hook', function (): void {
    AuthorizationMatrix::for($this)->expectAccessAs(
        Role::Customer,
        'GET',
        static fn (Ticket $ticket): string => "/_matrix/tickets/{$ticket->number}",
        404,
        relation: TicketRelation::Others,
    );
});

it('sends guests through unauthenticated', function (): void {
    $ticket = Ticket::factory()->create();
    $matrix = AuthorizationMatrix::for($this)->asGuest();

    $matrix->expectAccess('GET', "/_matrix/tickets/{$ticket->number}", 302);
    $matrix->expectAccess('GET', "/api/_matrix/tickets/{$ticket->number}", 401);
});
```

- [ ] **Step 2: Run the test and confirm it fails.**
  Command: `./vendor/bin/pest tests/Feature/Authorization/AuthorizationMatrixTest.php`
  Expected: FAIL. Pest 4 aborts while loading the file with `Pest\Exceptions\DatasetMissing` for `it creates a user with the requested role`: the `AuthorizationMatrix::roles()` dataset can't be built while `Tests\Support\AuthorizationMatrix` doesn't exist.

- [ ] **Step 3: Create the relation enum.** Create `tests/Support/TicketRelation.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

enum TicketRelation: string
{
    case Own = 'own';

    case Assigned = 'assigned';

    case Unassigned = 'unassigned';

    case Others = 'others';
}
```

- [ ] **Step 4: Create the helper.** Create `tests/Support/AuthorizationMatrix.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use BackedEnum;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

final class AuthorizationMatrix
{
    private ?User $user = null;

    private function __construct(private readonly TestCase $test) {}

    public static function for(TestCase $test): self
    {
        return new self($test);
    }

    /** @param array<string, mixed> $attributes */
    public function actingAsRole(Role $role, array $attributes = []): User
    {
        $factory = match ($role) {
            Role::Admin => User::factory()->admin(),
            Role::Agent => User::factory()->agent(),
            Role::Customer => User::factory()->customer(),
        };

        $this->user = $factory->create($attributes);

        return $this->user;
    }

    public function asGuest(): self
    {
        $this->user = null;
        app('auth')->forgetGuards();

        return $this;
    }

    public function user(): User
    {
        return $this->user ?? throw new LogicException('Call actingAsRole() before using the matrix user.');
    }

    /** @param  array<string, mixed>  $attributes */
    public function ticketFor(TicketRelation $relation, array $attributes = []): Ticket
    {
        $user = $this->user();

        return match ($relation) {
            TicketRelation::Own => Ticket::factory()->create(array_merge([
                'customer_id' => $user->id,
                'created_by_id' => $user->id,
                'assigned_agent_id' => null,
            ], $attributes)),
            TicketRelation::Assigned => Ticket::factory()->assignedTo($user)->create($attributes),
            TicketRelation::Unassigned => Ticket::factory()->create(array_merge([
                'assigned_agent_id' => null,
            ], $attributes)),
            TicketRelation::Others => Ticket::factory()
                ->assignedTo(User::factory()->agent()->create())
                ->create($attributes),
        };
    }

    /** @param array<string, mixed> $payload */
    public function expectAccess(string $method, string $uri, int $status, array $payload = []): TestResponse
    {
        return $this->send($method, $uri, $payload)->assertStatus($status);
    }

    /**
     * @param  string|Closure(Ticket): string  $uri
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $ticketAttributes
     */
    public function expectAccessAs(
        Role $role,
        string $method,
        string|Closure $uri,
        int $status,
        array $payload = [],
        ?TicketRelation $relation = null,
        array $ticketAttributes = [],
    ): TestResponse {
        $this->actingAsRole($role);

        if ($uri instanceof Closure) {
            if ($relation === null) {
                throw new LogicException('A URI closure needs a TicketRelation to build its ticket.');
            }

            $uri = $uri($this->ticketFor($relation, $ticketAttributes));
        }

        return $this->expectAccess($method, $uri, $status, $payload);
    }

    public static function statusOf(Response $response): int
    {
        return $response->allowed() ? 200 : ($response->status() ?? 403);
    }

    /** @return array<string, array{0: Role}> */
    public static function roles(): array
    {
        return [
            Role::Admin->value => [Role::Admin],
            Role::Agent->value => [Role::Agent],
            Role::Customer->value => [Role::Customer],
        ];
    }

    /**
     * @param  list<array{0: Role, 1: TicketRelation, 2: TicketStatus, 3: string, 4: list<mixed>, 5: int}>  $rows
     * @return array<string, array{0: Role, 1: TicketRelation, 2: TicketStatus, 3: string, 4: list<mixed>, 5: int}>
     */
    public static function ticketCases(array $rows): array
    {
        $cases = [];

        foreach ($rows as $row) {
            [$role, $relation, $status, $ability, $arguments, $expected] = $row;
            $label = sprintf(
                '%s · %s · %s · %s%s → %d',
                $role->value,
                $relation->value,
                $status->value,
                $ability,
                self::describeArguments($arguments),
                $expected,
            );

            if (array_key_exists($label, $cases)) {
                throw new LogicException("Duplicate authorization case [{$label}].");
            }

            $cases[$label] = $row;
        }

        return $cases;
    }

    /** @param list<mixed> $arguments */
    private static function describeArguments(array $arguments): string
    {
        if ($arguments === []) {
            return '';
        }

        $parts = array_map(
            static fn (mixed $argument): string => $argument instanceof BackedEnum ? (string) $argument->value : get_debug_type($argument),
            $arguments,
        );

        return '('.implode(', ', $parts).')';
    }

    /** @param array<string, mixed> $payload */
    private function send(string $method, string $uri, array $payload): TestResponse
    {
        $path = parse_url($uri, PHP_URL_PATH);
        $isApi = str_starts_with(ltrim(is_string($path) ? $path : $uri, '/'), 'api/');

        if ($isApi) {
            if ($this->user !== null) {
                Sanctum::actingAs($this->user, ['*']);
            }

            return $this->test->json($method, $uri, $payload);
        }

        if ($this->user !== null) {
            $this->test->actingAs($this->user, 'web');
        }

        return $this->test->call($method, $uri, $payload);
    }
}
```

- [ ] **Step 5: Run the test and confirm it passes.**
  Command: `./vendor/bin/pest tests/Feature/Authorization/AuthorizationMatrixTest.php`
  Expected: PASS, 0 failures.

- [ ] **Step 6: Format.** Command: `./vendor/bin/pint --dirty`. Expected: exits 0.

- [ ] **Step 7: Static analysis.** Command: `./vendor/bin/phpstan analyse --memory-limit=1G`. Expected: `[OK] No errors`.

- [ ] **Step 8: Commit.**
  ```bash
  git add tests/Support/AuthorizationMatrix.php tests/Support/TicketRelation.php tests/Feature/Authorization/AuthorizationMatrixTest.php
  git commit -m "test(auth): add authorization matrix test helper" --trailer "Refs: T04.1" --trailer "Implements: TD-010, TD-012"
  ```

### 4.2 TicketPolicy (TD-010, TD-011, TD-012)

- [ ] **Step 1: Write the failing test.** Create `tests/Feature/Authorization/TicketPolicyTest.php`. It is a policy-level matrix (routes arrive in T05+) with the columns role, relation, ticket status, ability, extra gate arguments, and expected status (200 allowed, 403 denied, 404 denied as not found). A customer sees their own ticket, may close or reopen their own Resolved ticket and nothing else, and gets 404 for every ability on someone else's. An agent has full rights on a ticket assigned to them and on the unassigned queue; on a colleague's ticket the policy allows `claim`, because `ClaimTicket` answers 409 (TD-051). An admin may do everything except converse on Closed tickets:

```php
<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Support\Facades\Gate;
use Tests\Support\AuthorizationMatrix;
use Tests\Support\TicketRelation;

$customer = Role::Customer;
$agent = Role::Agent;
$admin = Role::Admin;
$own = TicketRelation::Own;
$assigned = TicketRelation::Assigned;
$unassigned = TicketRelation::Unassigned;
$others = TicketRelation::Others;
$open = TicketStatus::Open;
$inProgress = TicketStatus::InProgress;
$waiting = TicketStatus::WaitingForCustomer;
$resolved = TicketStatus::Resolved;
$closed = TicketStatus::Closed;

$rows = [
    [$customer, $own, $open, 'view', [], 200],
    [$customer, $own, $open, 'update', [], 200],
    [$customer, $own, $open, 'changeStatus', [], 403],
    [$customer, $own, $open, 'changePriority', [], 403],
    [$customer, $own, $open, 'claim', [], 403],
    [$customer, $own, $open, 'assign', [], 403],
    [$customer, $own, $open, 'reply', [], 200],
    [$customer, $own, $open, 'addInternalNote', [], 403],
    [$customer, $own, $open, 'viewInternal', [], 403],
    [$customer, $own, $inProgress, 'update', [], 403],
    [$customer, $own, $inProgress, 'reply', [], 200],
    [$customer, $own, $inProgress, 'changeStatus', [], 403],
    [$customer, $own, $inProgress, 'changeStatus', [$closed], 403],
    [$customer, $own, $waiting, 'update', [], 403],
    [$customer, $own, $waiting, 'reply', [], 200],
    [$customer, $own, $resolved, 'changeStatus', [], 200],
    [$customer, $own, $resolved, 'changeStatus', [$closed], 200],
    [$customer, $own, $resolved, 'changeStatus', [$inProgress], 200],
    [$customer, $own, $resolved, 'changeStatus', [$waiting], 403],
    [$customer, $own, $resolved, 'changeStatus', [$open], 403],
    [$customer, $own, $resolved, 'update', [], 403],
    [$customer, $own, $resolved, 'reply', [], 200],
    [$customer, $own, $closed, 'view', [], 200],
    [$customer, $own, $closed, 'changeStatus', [], 403],
    [$customer, $own, $closed, 'changeStatus', [$inProgress], 403],
    [$customer, $own, $closed, 'reply', [], 403],
    [$customer, $others, $open, 'view', [], 404],
    [$customer, $others, $open, 'update', [], 404],
    [$customer, $others, $open, 'changeStatus', [], 404],
    [$customer, $others, $open, 'changePriority', [], 404],
    [$customer, $others, $open, 'claim', [], 404],
    [$customer, $others, $open, 'assign', [], 404],
    [$customer, $others, $open, 'reply', [], 404],
    [$customer, $others, $open, 'addInternalNote', [], 404],
    [$customer, $others, $open, 'viewInternal', [], 404],
    [$customer, $others, $resolved, 'changeStatus', [$closed], 404],
    [$customer, $unassigned, $open, 'view', [], 404],
    [$customer, $unassigned, $open, 'reply', [], 404],
    [$agent, $assigned, $inProgress, 'view', [], 200],
    [$agent, $assigned, $inProgress, 'update', [], 200],
    [$agent, $assigned, $inProgress, 'changeStatus', [], 200],
    [$agent, $assigned, $inProgress, 'changeStatus', [$resolved], 200],
    [$agent, $assigned, $inProgress, 'changePriority', [], 200],
    [$agent, $assigned, $inProgress, 'claim', [], 200],
    [$agent, $assigned, $inProgress, 'assign', [], 200],
    [$agent, $assigned, $inProgress, 'reply', [], 200],
    [$agent, $assigned, $inProgress, 'addInternalNote', [], 200],
    [$agent, $assigned, $inProgress, 'viewInternal', [], 200],
    [$agent, $assigned, $closed, 'view', [], 200],
    [$agent, $assigned, $closed, 'reply', [], 403],
    [$agent, $assigned, $closed, 'addInternalNote', [], 403],
    [$agent, $assigned, $closed, 'viewInternal', [], 200],
    [$agent, $unassigned, $open, 'view', [], 200],
    [$agent, $unassigned, $open, 'update', [], 403],
    [$agent, $unassigned, $open, 'changeStatus', [], 403],
    [$agent, $unassigned, $open, 'changePriority', [], 403],
    [$agent, $unassigned, $open, 'claim', [], 200],
    [$agent, $unassigned, $open, 'assign', [], 403],
    [$agent, $unassigned, $open, 'reply', [], 200],
    [$agent, $unassigned, $open, 'addInternalNote', [], 200],
    [$agent, $unassigned, $open, 'viewInternal', [], 200],
    [$agent, $unassigned, $resolved, 'claim', [], 403],
    [$agent, $unassigned, $closed, 'claim', [], 403],
    [$agent, $others, $inProgress, 'view', [], 200],
    [$agent, $others, $inProgress, 'update', [], 403],
    [$agent, $others, $inProgress, 'changeStatus', [], 403],
    [$agent, $others, $inProgress, 'changeStatus', [$resolved], 403],
    [$agent, $others, $inProgress, 'changePriority', [], 403],
    [$agent, $others, $inProgress, 'claim', [], 200],
    [$agent, $others, $inProgress, 'assign', [], 403],
    [$agent, $others, $inProgress, 'reply', [], 200],
    [$agent, $others, $inProgress, 'addInternalNote', [], 200],
    [$agent, $others, $inProgress, 'viewInternal', [], 200],
    [$admin, $others, $inProgress, 'view', [], 200],
    [$admin, $others, $inProgress, 'update', [], 200],
    [$admin, $others, $inProgress, 'changeStatus', [], 200],
    [$admin, $others, $inProgress, 'changeStatus', [$resolved], 200],
    [$admin, $others, $inProgress, 'changePriority', [], 200],
    [$admin, $others, $inProgress, 'claim', [], 200],
    [$admin, $others, $inProgress, 'assign', [], 200],
    [$admin, $others, $inProgress, 'reply', [], 200],
    [$admin, $others, $inProgress, 'addInternalNote', [], 200],
    [$admin, $others, $inProgress, 'viewInternal', [], 200],
    [$admin, $unassigned, $open, 'update', [], 200],
    [$admin, $unassigned, $open, 'claim', [], 200],
    [$admin, $unassigned, $open, 'assign', [], 200],
    [$admin, $others, $closed, 'view', [], 200],
    [$admin, $others, $closed, 'reply', [], 403],
    [$admin, $others, $closed, 'addInternalNote', [], 403],
];

it('authorizes class-level ticket abilities by role', function (Role $role, string $ability, int $expected): void {
    $matrix = AuthorizationMatrix::for($this);
    $user = $matrix->actingAsRole($role);

    expect(AuthorizationMatrix::statusOf(Gate::forUser($user)->inspect($ability, Ticket::class)))->toBe($expected);
})->with([
    'customer · viewAny → 200' => [Role::Customer, 'viewAny', 200],
    'agent · viewAny → 200' => [Role::Agent, 'viewAny', 200],
    'admin · viewAny → 200' => [Role::Admin, 'viewAny', 200],
    'customer · create → 200' => [Role::Customer, 'create', 200],
    'agent · create → 200' => [Role::Agent, 'create', 200],
    'admin · create → 200' => [Role::Admin, 'create', 200],
    'customer · createOnBehalf → 403' => [Role::Customer, 'createOnBehalf', 403],
    'agent · createOnBehalf → 200' => [Role::Agent, 'createOnBehalf', 200],
    'admin · createOnBehalf → 200' => [Role::Admin, 'createOnBehalf', 200],
]);

it('authorizes ticket abilities by role, ownership and status', function (
    Role $role,
    TicketRelation $relation,
    TicketStatus $status,
    string $ability,
    array $arguments,
    int $expected,
): void {
    $matrix = AuthorizationMatrix::for($this);
    $user = $matrix->actingAsRole($role);
    $ticket = $matrix->ticketFor($relation, ['status' => $status]);

    $response = Gate::forUser($user)->inspect($ability, [$ticket, ...$arguments]);

    expect(AuthorizationMatrix::statusOf($response))->toBe($expected);
})->with(AuthorizationMatrix::ticketCases($rows));

it('uses a 404 status for invisible tickets so authorize() renders not found', function (): void {
    $matrix = AuthorizationMatrix::for($this);
    $customer = $matrix->actingAsRole(Role::Customer);
    $ticket = $matrix->ticketFor(TicketRelation::Others);

    $response = Gate::forUser($customer)->inspect('view', $ticket);

    expect($response->denied())->toBeTrue()
        ->and($response->status())->toBe(404);
});
```

- [ ] **Step 2: Run the test and confirm it fails.**
  Command: `./vendor/bin/pest tests/Feature/Authorization/TicketPolicyTest.php`
  Expected: FAIL. The rows expecting 200 or 404 fail with `Failed asserting that 403 is identical to 200.` (or `... to 404.`), because without a policy every ability is denied.

- [ ] **Step 3: Implement.** Create `app/Policies/TicketPolicy.php`. The policy deliberately doesn't check `assigned_agent_id` for `claim`: a lost race must surface as 409 `TicketAlreadyClaimed` from `ClaimTicket` (TD-051), not as a 403 here. A customer may close or reopen their own Resolved ticket, and do nothing else with its status:

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\Response;

final class TicketPolicy
{
    private const CUSTOMER_TARGETS_FROM_RESOLVED = [TicketStatus::Closed, TicketStatus::InProgress];

    public function viewAny(User $user): bool
    {
        return $this->has($user, Permission::TicketsViewAny)
            || $this->has($user, Permission::TicketsViewOwn);
    }

    public function view(User $user, Ticket $ticket): Response
    {
        return $this->decide($user, $ticket, static fn (): bool => true);
    }

    public function create(User $user): bool
    {
        return $this->has($user, Permission::TicketsCreate);
    }

    public function createOnBehalf(User $user): bool
    {
        return $this->has($user, Permission::TicketsCreateOnBehalf);
    }

    public function update(User $user, Ticket $ticket): Response
    {
        return $this->decide($user, $ticket, fn (): bool => $this->manages($user, $ticket)
            || ($this->isCustomerOf($user, $ticket)
                && $this->has($user, Permission::TicketsUpdateOwn)
                && $ticket->status === TicketStatus::Open));
    }

    public function changeStatus(User $user, Ticket $ticket, ?TicketStatus $to = null): Response
    {
        return $this->decide($user, $ticket, fn (): bool => $this->manages($user, $ticket)
            || ($this->isCustomerOf($user, $ticket)
                && $this->has($user, Permission::TicketsUpdateOwn)
                && $ticket->status === TicketStatus::Resolved
                && ($to === null || in_array($to, self::CUSTOMER_TARGETS_FROM_RESOLVED, true))));
    }

    public function changePriority(User $user, Ticket $ticket): Response
    {
        return $this->decide($user, $ticket, fn (): bool => $this->manages($user, $ticket));
    }

    public function claim(User $user, Ticket $ticket): Response
    {
        return $this->decide($user, $ticket, fn (): bool => $this->has($user, Permission::TicketsClaim)
            && $ticket->status->isActive());
    }

    public function assign(User $user, Ticket $ticket): Response
    {
        return $this->decide($user, $ticket, fn (): bool => $this->has($user, Permission::TicketsAssignAny)
            || ($this->has($user, Permission::TicketsHandoffOwn) && $this->isAssigneeOf($user, $ticket)));
    }

    public function reply(User $user, Ticket $ticket): Response
    {
        return $this->decide($user, $ticket, fn (): bool => $this->has($user, Permission::TicketsReply)
            && $ticket->status !== TicketStatus::Closed);
    }

    public function addInternalNote(User $user, Ticket $ticket): Response
    {
        return $this->decide($user, $ticket, fn (): bool => $this->has($user, Permission::TicketsNotesInternal)
            && $ticket->status !== TicketStatus::Closed);
    }

    public function viewInternal(User $user, Ticket $ticket): Response
    {
        return $this->decide($user, $ticket, fn (): bool => $this->has($user, Permission::TicketsNotesInternal));
    }

    /** @param Closure(): bool $rule */
    private function decide(User $user, Ticket $ticket, Closure $rule): Response
    {
        if (! $this->canSee($user, $ticket)) {
            return Response::denyAsNotFound();
        }

        return $rule() ? Response::allow() : Response::deny();
    }

    private function canSee(User $user, Ticket $ticket): bool
    {
        return $this->has($user, Permission::TicketsViewAny)
            || ($this->has($user, Permission::TicketsViewOwn) && $this->isCustomerOf($user, $ticket));
    }

    private function manages(User $user, Ticket $ticket): bool
    {
        return $this->has($user, Permission::TicketsUpdateAny)
            || ($this->has($user, Permission::TicketsUpdateAssigned) && $this->isAssigneeOf($user, $ticket));
    }

    private function isCustomerOf(User $user, Ticket $ticket): bool
    {
        return $ticket->customer_id === $user->id;
    }

    private function isAssigneeOf(User $user, Ticket $ticket): bool
    {
        return $ticket->assigned_agent_id === $user->id;
    }

    private function has(User $user, Permission $permission): bool
    {
        return $user->can($permission->value);
    }
}
```

- [ ] **Step 4: Run the test and confirm it passes.**
  Command: `./vendor/bin/pest tests/Feature/Authorization/TicketPolicyTest.php`
  Expected: PASS, 0 failures (89 matrix rows plus 10 other cases). If a row fails only for one role, check T01's `Permission::forRole()` against the baseline in §3 "Consumes". If they differ, stop and report rather than changing the expectation.

- [ ] **Step 5: Format.** Command: `./vendor/bin/pint --dirty`. Expected: exits 0.

- [ ] **Step 6: Static analysis.** Command: `./vendor/bin/phpstan analyse --memory-limit=1G`. Expected: `[OK] No errors`.

- [ ] **Step 7: Commit.**
  ```bash
  git add app/Policies/TicketPolicy.php tests/Feature/Authorization/TicketPolicyTest.php
  git commit -m "feat(auth): add ticket policy with role and ownership rules" --trailer "Refs: T04.2" --trailer "Implements: TD-010, TD-011, TD-012"
  ```

### 4.3 TicketReplyPolicy + AttachmentPolicy (TD-024, TD-120)

- [ ] **Step 1: Write the failing reply test.** Create `tests/Feature/Authorization/TicketReplyPolicyTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\TicketReply;
use Illuminate\Support\Facades\Gate;
use Tests\Support\AuthorizationMatrix;
use Tests\Support\TicketRelation;

it('hides internal replies and invisible tickets from customers', function (Role $role, TicketRelation $relation, bool $internal, int $expected): void {
    $matrix = AuthorizationMatrix::for($this);
    $user = $matrix->actingAsRole($role);
    $ticket = $matrix->ticketFor($relation);
    $factory = TicketReply::factory()->for($ticket);
    $reply = ($internal ? $factory->internal() : $factory)->create();

    expect(AuthorizationMatrix::statusOf(Gate::forUser($user)->inspect('view', $reply)))->toBe($expected);
})->with([
    'customer · own ticket · public reply → 200' => [Role::Customer, TicketRelation::Own, false, 200],
    'customer · own ticket · internal note → 404' => [Role::Customer, TicketRelation::Own, true, 404],
    'customer · other ticket · public reply → 404' => [Role::Customer, TicketRelation::Others, false, 404],
    'customer · other ticket · internal note → 404' => [Role::Customer, TicketRelation::Others, true, 404],
    'agent · colleague ticket · public reply → 200' => [Role::Agent, TicketRelation::Others, false, 200],
    'agent · colleague ticket · internal note → 200' => [Role::Agent, TicketRelation::Others, true, 200],
    'agent · unassigned ticket · internal note → 200' => [Role::Agent, TicketRelation::Unassigned, true, 200],
    'admin · any ticket · internal note → 200' => [Role::Admin, TicketRelation::Others, true, 200],
]);
```

- [ ] **Step 2: Write the failing attachment test.** Create `tests/Feature/Authorization/AttachmentPolicyTest.php`. An attachment on a reply inherits the reply's `is_internal` flag at creation (TD-024):

```php
<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Attachment;
use App\Models\TicketReply;
use Illuminate\Support\Facades\Gate;
use Tests\Support\AuthorizationMatrix;
use Tests\Support\TicketRelation;

it('authorizes downloads by ticket visibility and the internal flag', function (Role $role, TicketRelation $relation, bool $internal, int $expected): void {
    $matrix = AuthorizationMatrix::for($this);
    $user = $matrix->actingAsRole($role);
    $ticket = $matrix->ticketFor($relation);
    $attachment = Attachment::factory()->for($ticket)->create(['reply_id' => null, 'is_internal' => $internal]);

    expect(AuthorizationMatrix::statusOf(Gate::forUser($user)->inspect('view', $attachment)))->toBe($expected);
})->with([
    'customer · own ticket · public file → 200' => [Role::Customer, TicketRelation::Own, false, 200],
    'customer · own ticket · internal file → 404' => [Role::Customer, TicketRelation::Own, true, 404],
    'customer · other ticket · public file → 404' => [Role::Customer, TicketRelation::Others, false, 404],
    'customer · other ticket · internal file → 404' => [Role::Customer, TicketRelation::Others, true, 404],
    'agent · colleague ticket · public file → 200' => [Role::Agent, TicketRelation::Others, false, 200],
    'agent · colleague ticket · internal file → 200' => [Role::Agent, TicketRelation::Others, true, 200],
    'admin · any ticket · internal file → 200' => [Role::Admin, TicketRelation::Others, true, 200],
]);

it('never lets a customer download a file attached to an internal note on their own ticket', function (): void {
    $matrix = AuthorizationMatrix::for($this);
    $customer = $matrix->actingAsRole(Role::Customer);
    $ticket = $matrix->ticketFor(TicketRelation::Own);
    $note = TicketReply::factory()->for($ticket)->internal()->create();
    $attachment = Attachment::factory()->for($ticket)->create(['reply_id' => $note->id, 'is_internal' => true]);

    $response = Gate::forUser($customer)->inspect('view', $attachment);

    expect($response->denied())->toBeTrue()
        ->and($response->status())->toBe(404);
});
```

- [ ] **Step 3: Run both tests and confirm they fail.**
  Command: `./vendor/bin/pest tests/Feature/Authorization/TicketReplyPolicyTest.php tests/Feature/Authorization/AttachmentPolicyTest.php`
  Expected: FAIL. The rows expecting 200 or 404 fail with `Failed asserting that 403 is identical to 200.` (or `... to 404.`).

- [ ] **Step 4: Implement the reply policy.** Create `app/Policies/TicketReplyPolicy.php`. Internal notes are reported to customers as not found, never as forbidden (TD-012, TD-120):

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TicketReply;
use App\Models\User;
use Illuminate\Auth\Access\Response;

final class TicketReplyPolicy
{
    public function __construct(private readonly TicketPolicy $tickets) {}

    public function view(User $user, TicketReply $reply): Response
    {
        $ticket = $reply->ticket;
        $ticketAccess = $this->tickets->view($user, $ticket);

        if ($ticketAccess->denied()) {
            return $ticketAccess;
        }

        if ($reply->is_internal && $this->tickets->viewInternal($user, $ticket)->denied()) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }
}
```

- [ ] **Step 5: Implement the attachment policy.** Create `app/Policies/AttachmentPolicy.php`. The user must see the ticket, and internal files are staff-only. The denormalized `ticket_id` and `is_internal` make this a single check with no reply lookup (TD-024):

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Attachment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

final class AttachmentPolicy
{
    public function __construct(private readonly TicketPolicy $tickets) {}

    public function view(User $user, Attachment $attachment): Response
    {
        $ticket = $attachment->ticket;
        $ticketAccess = $this->tickets->view($user, $ticket);

        if ($ticketAccess->denied()) {
            return $ticketAccess;
        }

        if ($attachment->is_internal && $this->tickets->viewInternal($user, $ticket)->denied()) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }
}
```

- [ ] **Step 6: Run both tests and confirm they pass.**
  Command: `./vendor/bin/pest tests/Feature/Authorization/TicketReplyPolicyTest.php tests/Feature/Authorization/AttachmentPolicyTest.php`
  Expected: PASS, 0 failures.

- [ ] **Step 7: Format.** Command: `./vendor/bin/pint --dirty`. Expected: exits 0.

- [ ] **Step 8: Static analysis.** Command: `./vendor/bin/phpstan analyse --memory-limit=1G`. Expected: `[OK] No errors`.

- [ ] **Step 9: Commit.**
  ```bash
  git add app/Policies/TicketReplyPolicy.php app/Policies/AttachmentPolicy.php tests/Feature/Authorization/TicketReplyPolicyTest.php tests/Feature/Authorization/AttachmentPolicyTest.php
  git commit -m "feat(auth): add reply and attachment policies hiding internal content" --trailer "Refs: T04.3" --trailer "Implements: TD-024, TD-120"
  ```

### 4.4 Admin policies + viewDashboard Gate (TD-010, TD-011)

- [ ] **Step 1: Write the failing admin test.** Create `tests/Feature/Authorization/AdminPoliciesTest.php`. Policies only check capabilities, so an unsaved model instance is enough for update and delete:

```php
<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Category;
use App\Models\Holiday;
use App\Models\TicketImport;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\Support\AuthorizationMatrix;

it('limits admin areas to admins', function (string $model, string $ability, Role $role, int $expected): void {
    $user = AuthorizationMatrix::for($this)->actingAsRole($role);
    $argument = in_array($ability, ['viewAny', 'create'], true) ? $model : new $model;

    expect(AuthorizationMatrix::statusOf(Gate::forUser($user)->inspect($ability, $argument)))->toBe($expected);
})->with([
    'categories' => [Category::class],
    'holidays' => [Holiday::class],
    'users' => [User::class],
    'imports' => [TicketImport::class],
])->with(['viewAny', 'create', 'update', 'delete'])
    ->with([
        'admin → 200' => [Role::Admin, 200],
        'agent → 403' => [Role::Agent, 403],
        'customer → 403' => [Role::Customer, 403],
    ]);

it('lets only admins open an import', function (Role $role, int $expected): void {
    $user = AuthorizationMatrix::for($this)->actingAsRole($role);

    expect(AuthorizationMatrix::statusOf(Gate::forUser($user)->inspect('view', new TicketImport)))->toBe($expected);
})->with([
    'admin → 200' => [Role::Admin, 200],
    'agent → 403' => [Role::Agent, 403],
    'customer → 403' => [Role::Customer, 403],
]);

it('stops an admin from deleting their own account', function (): void {
    $admin = AuthorizationMatrix::for($this)->actingAsRole(Role::Admin);
    $other = User::factory()->agent()->create();

    expect($admin->can('delete', $admin))->toBeFalse()
        ->and($admin->can('delete', $other))->toBeTrue()
        ->and($admin->can('update', $admin))->toBeTrue();
});
```

- [ ] **Step 2: Write the failing gate test.** Create `tests/Feature/Authorization/DashboardGateTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\Role;
use Illuminate\Support\Facades\Gate;
use Tests\Support\AuthorizationMatrix;

it('lets staff view the dashboard and keeps customers out', function (Role $role, bool $expected): void {
    $user = AuthorizationMatrix::for($this)->actingAsRole($role);

    expect(Gate::forUser($user)->allows('viewDashboard'))->toBe($expected)
        ->and($user->can('viewDashboard'))->toBe($expected);
})->with([
    'admin' => [Role::Admin, true],
    'agent' => [Role::Agent, true],
    'customer' => [Role::Customer, false],
]);
```

- [ ] **Step 3: Run both tests and confirm they fail.**
  Command: `./vendor/bin/pest tests/Feature/Authorization/AdminPoliciesTest.php tests/Feature/Authorization/DashboardGateTest.php`
  Expected: FAIL. The admin rows fail with `Failed asserting that 403 is identical to 200.` The gate rows for admin and agent fail with `Failed asserting that false is identical to true.`

- [ ] **Step 4: Create `app/Policies/CategoryPolicy.php`.**

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Category;
use App\Models\User;

final class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::CategoriesManage->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::CategoriesManage->value);
    }

    public function update(User $user, Category $category): bool
    {
        return $user->can(Permission::CategoriesManage->value);
    }

    public function delete(User $user, Category $category): bool
    {
        return $user->can(Permission::CategoriesManage->value);
    }
}
```

- [ ] **Step 5: Create `app/Policies/HolidayPolicy.php`.**

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Holiday;
use App\Models\User;

final class HolidayPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::HolidaysManage->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::HolidaysManage->value);
    }

    public function update(User $user, Holiday $holiday): bool
    {
        return $user->can(Permission::HolidaysManage->value);
    }

    public function delete(User $user, Holiday $holiday): bool
    {
        return $user->can(Permission::HolidaysManage->value);
    }
}
```

- [ ] **Step 6: Create `app/Policies/UserPolicy.php`.** An admin can't delete themselves, because that could leave the system without an administrator.

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::UsersManage->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::UsersManage->value);
    }

    public function update(User $user, User $model): bool
    {
        return $user->can(Permission::UsersManage->value);
    }

    public function delete(User $user, User $model): bool
    {
        return $user->can(Permission::UsersManage->value) && $model->isNot($user);
    }
}
```

- [ ] **Step 7: Create `app/Policies/TicketImportPolicy.php`.**

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\TicketImport;
use App\Models\User;

final class TicketImportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ImportsManage->value);
    }

    public function view(User $user, TicketImport $import): bool
    {
        return $user->can(Permission::ImportsManage->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ImportsManage->value);
    }

    public function update(User $user, TicketImport $import): bool
    {
        return $user->can(Permission::ImportsManage->value);
    }

    public function delete(User $user, TicketImport $import): bool
    {
        return $user->can(Permission::ImportsManage->value);
    }
}
```

- [ ] **Step 8: Create `app/Policies/DashboardPolicy.php`.** It isn't a model policy: it is registered as the `viewDashboard` Gate in `AppServiceProvider`.

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

final class DashboardPolicy
{
    public function view(User $user): bool
    {
        return $user->can(Permission::DashboardView->value);
    }
}
```

- [ ] **Step 9: Register the Gate.** In `app/Providers/AppServiceProvider.php`, add the two imports below to the `use` block. Then add the `Gate::define` line as the **last statement** of `boot()`. Leave everything T00 wrote untouched.

```php
use App\Policies\DashboardPolicy;
use Illuminate\Support\Facades\Gate;
```

```php
        Gate::define('viewDashboard', [DashboardPolicy::class, 'view']);
```

- [ ] **Step 10: Run both tests and confirm they pass.**
  Command: `./vendor/bin/pest tests/Feature/Authorization/AdminPoliciesTest.php tests/Feature/Authorization/DashboardGateTest.php`
  Expected: PASS, 0 failures.

- [ ] **Step 11: Format.** Command: `./vendor/bin/pint --dirty`. Expected: exits 0.

- [ ] **Step 12: Static analysis.** Command: `./vendor/bin/phpstan analyse --memory-limit=1G`. Expected: `[OK] No errors`.

- [ ] **Step 13: Commit.**
  ```bash
  git add app/Policies/CategoryPolicy.php app/Policies/HolidayPolicy.php app/Policies/UserPolicy.php app/Policies/TicketImportPolicy.php app/Policies/DashboardPolicy.php app/Providers/AppServiceProvider.php tests/Feature/Authorization/AdminPoliciesTest.php tests/Feature/Authorization/DashboardGateTest.php
  git commit -m "feat(auth): add admin policies and viewDashboard gate" --trailer "Refs: T04.4" --trailer "Implements: TD-010, TD-011"
  ```

### 4.5 Shared auth props in HandleInertiaRequests (TD-120)

- [ ] **Step 1: Write the failing test.** Create `tests/Feature/Authorization/SharedAuthPropsTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Http\Request;
use Tests\Support\AuthorizationMatrix;

/** @return array<string, mixed> */
function sharedPropsFor(?User $user): array
{
    $request = Request::create('/');
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(static fn (): ?User => $user);

    return app(HandleInertiaRequests::class)->share($request);
}

it('shares only explicit user fields plus role names', function (Role $role): void {
    $user = AuthorizationMatrix::for($this)->actingAsRole($role);

    expect(sharedPropsFor($user)['auth']['user'])->toBe([
        'id' => $user->id,
        'name' => $user->name,
        'email' => $user->email,
        'email_verified_at' => $user->email_verified_at?->toIso8601String(),
        'roles' => [$role->value],
    ]);
})->with(AuthorizationMatrix::roles());

it('shares the permission booleans the UI uses to hide controls', function (Role $role, array $expected): void {
    $user = AuthorizationMatrix::for($this)->actingAsRole($role);

    expect(sharedPropsFor($user)['auth']['can'])->toBe($expected);
})->with([
    'admin' => [Role::Admin, [
        'viewDashboard' => true, 'manageImports' => true, 'manageUsers' => true,
        'manageCategories' => true, 'manageHolidays' => true, 'createOnBehalf' => true,
    ]],
    'agent' => [Role::Agent, [
        'viewDashboard' => true, 'manageImports' => false, 'manageUsers' => false,
        'manageCategories' => false, 'manageHolidays' => false, 'createOnBehalf' => true,
    ]],
    'customer' => [Role::Customer, [
        'viewDashboard' => false, 'manageImports' => false, 'manageUsers' => false,
        'manageCategories' => false, 'manageHolidays' => false, 'createOnBehalf' => false,
    ]],
]);

it('shares a null user and no capabilities with guests', function (): void {
    expect(sharedPropsFor(null)['auth'])->toBe([
        'user' => null,
        'can' => [
            'viewDashboard' => false, 'manageImports' => false, 'manageUsers' => false,
            'manageCategories' => false, 'manageHolidays' => false, 'createOnBehalf' => false,
        ],
    ]);
});
```

- [ ] **Step 2: Run the test and confirm it fails.**
  Command: `./vendor/bin/pest tests/Feature/Authorization/SharedAuthPropsTest.php`
  Expected: FAIL. The user cases fail with `Failed asserting that ... is identical to Array (...)` (no `roles`), and the `can` cases fail with `Undefined array key "can"` or a mismatch, depending on T00's baseline.

- [ ] **Step 3: Add the imports.** In `app/Http/Middleware/HandleInertiaRequests.php`, add any of these that aren't already there to the `use` block:

```php
use App\Models\Category;
use App\Models\Holiday;
use App\Models\Ticket;
use App\Models\TicketImport;
use App\Models\User;
use Illuminate\Http\Request;
```

- [ ] **Step 4: Add the key list.** These are the keys of `auth.can` (README §3.13); guests get all of them as false. In the same class, add this constant directly after the class's opening brace (before `$rootView`):

```php
    private const AUTH_CAN_KEYS = [
        'viewDashboard',
        'manageImports',
        'manageUsers',
        'manageCategories',
        'manageHolidays',
        'createOnBehalf',
    ];
```

- [ ] **Step 5: Point the `auth` entry at the new method.** In `share()`, replace the whole value of the `'auth'` key (whatever T00 put there) with this line. Leave everything else exactly as it is: the `...parent::share($request)` spread (Inertia 3's base middleware shares `errors` there as an `Inertia::always()` prop), the starter kit's `name` and `sidebarOpen` keys, and `notifications` and `flash`:

```php
            'auth' => $this->authProps($request),
```

- [ ] **Step 6: Add the method.** Only explicitly selected user fields and the user's own capability booleans are shared (TD-120), and the booleans go through the same Policies and Gate as the server-side checks (TD-010). Add this private method at the end of the class:

```php
    /** @return array{user: array{id: int, name: string, email: string, roles: list<string>}|null, can: array<string, bool>} */
    private function authProps(Request $request): array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return ['user' => null, 'can' => array_fill_keys(self::AUTH_CAN_KEYS, false)];
        }

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'roles' => $user->getRoleNames()->values()->all(),
            ],
            'can' => [
                'viewDashboard' => $user->can('viewDashboard'),
                'manageImports' => $user->can('viewAny', TicketImport::class),
                'manageUsers' => $user->can('viewAny', User::class),
                'manageCategories' => $user->can('viewAny', Category::class),
                'manageHolidays' => $user->can('viewAny', Holiday::class),
                'createOnBehalf' => $user->can('createOnBehalf', Ticket::class),
            ],
        ];
    }
```

- [ ] **Step 7: Run the test and confirm it passes.**
  Command: `./vendor/bin/pest tests/Feature/Authorization/SharedAuthPropsTest.php`
  Expected: PASS, 0 failures.

- [ ] **Step 8: Run the whole authorization folder.**
  Command: `./vendor/bin/pest tests/Feature/Authorization`
  Expected: PASS, 0 failures.

- [ ] **Step 9: Format.** Command: `./vendor/bin/pint --dirty`. Expected: exits 0.

- [ ] **Step 10: Static analysis.** Command: `./vendor/bin/phpstan analyse --memory-limit=1G`. Expected: `[OK] No errors`.

- [ ] **Step 11: Commit.**
  ```bash
  git add app/Http/Middleware/HandleInertiaRequests.php tests/Feature/Authorization/SharedAuthPropsTest.php
  git commit -m "feat(auth): share user roles and abilities via Inertia" --trailer "Refs: T04.5" --trailer "Implements: TD-120"
  ```

## 6. Acceptance criteria

- [ ] Every policy and method in README §3.11 exists with the signatures in §3 "Produces". Each class is `final`, and each file has `declare(strict_types=1);`.
- [ ] Policies are auto-discovered (no manual `Gate::policy()` registration); the allowed rows of each policy's matrix fail without it.
- [ ] No policy calls `hasRole()` or `hasPermissionTo()`, and no role or permission is written as a string literal (`grep -rn "hasRole\|hasPermissionTo\|'tickets\.\|'admin'\|'agent'\|'customer'" app/Policies` returns nothing).
- [ ] A customer gets 404 (`denyAsNotFound`) for every ability on a ticket they don't own, and for internal replies and internal attachments on their own tickets.
- [ ] The customer special case of `changeStatus` holds: own `Resolved` ticket → `Closed` or `InProgress` allowed; any other target, status or ticket denied.
- [ ] `claim` doesn't check the current assignee (409 stays with `ClaimTicket`). `reply` and `addInternalNote` are denied on `Closed`.
- [ ] `Gate::define('viewDashboard', [DashboardPolicy::class, 'view'])` is registered in `AppServiceProvider::boot()`. Staff are allowed, customers denied.
- [ ] `HandleInertiaRequests` shares `auth.user{id,name,email,email_verified_at,roles[]}` and `auth.can{viewDashboard, manageImports, manageUsers, manageCategories, manageHolidays, createOnBehalf}` and nothing else from the user model. The `notifications` closure is unchanged.
- [ ] `Tests\Support\AuthorizationMatrix` supports policy-level checks (`statusOf`, `ticketCases`) and HTTP-level checks (`expectAccess`, `expectAccessAs` with the `ticketFor` ownership hook, web and API), and has its own self-test.

## 7. Verification

Run the gate in the worktree:

```bash
./vendor/bin/pint --test && ./vendor/bin/phpstan analyse --memory-limit=1G && ./vendor/bin/pest --parallel
```

Expected: Pint `PASS`, PHPStan `[OK] No errors`, Pest all green with 0 failures (including every test from T00 and T01). No `resources/js` changes, so the frontend gate is not needed.

## Contract notes for controller

1. **`TicketImportPolicy::view` added.** README §3.11 lists only `viewAny, create, update, delete` for the admin policies, but T11 has `admin.imports.show`, `imports.show` and the error routes, and can't edit this file. This task adds `view(User, TicketImport): bool` (admin capability). Please add it to §3.11.
2. **`categories.index` for any authenticated user (T13, API).** `CategoryPolicy::viewAny` is admin-only per §3.11 ("admin capabilities"), so it can't authorize the public category list. Suggest T13 authorizes that route with `can:create,App\Models\Ticket` (anyone who may create a ticket needs the category list), or that the contract adds a `CategoryPolicy::list` ability.
3. **`claim` ignores the current assignee**, so the race loser gets 409 (TD-051) and not 403. `TicketCan.claim` (T05) should therefore be `can('claim') && assigned_agent_id === null`, so the UI hides the button on assigned tickets.
4. **`reply`/`addInternalNote` deny `Closed` tickets with 403.** `AddReply` still throws `TicketClosed` (422) as the domain guard. T07's HTTP tests should expect 403 for a reply on a Closed ticket.
5. **Matrix helper shape.** README §4 names `actingAsRole(Role)` and `expectAccess(string $method, string $uri, int $status)`, while the task brief suggested a role-first `expectAccess(Role, ...)`. This plan keeps README's `expectAccess` (plus an optional `$payload`) and adds the role-first variant as `expectAccessAs(Role, ...)` with the `TicketRelation` ownership hook. The helper is an object (`AuthorizationMatrix::for($this)`) rather than a trait, because static calls on traits are deprecated. It also adds `tests/Support/TicketRelation.php`, which isn't in the §4 table.
6. **Assumed permission baseline.** The test expectations rely on the `Permission::forRole()` mapping listed in §3 "Consumes". If T01 seeded something different, the matrix fails; the implementer stops and reports instead of editing expectations.
7. **Edits to T00 files are insertions.** The `AppServiceProvider` and `HandleInertiaRequests` steps insert code rather than replace whole files, because T00's exact content isn't in a plan file yet. T00's `SharedProps` TypeScript type (in `resources/js/types`) must declare `auth.user.roles: string[]` and `auth.can` as in §3.13; T04 doesn't touch `resources/js`.
