# T13 — Admin configuration (categories, holidays, users)

## 1. Header

- **Objective:** give admins web and API CRUD for categories, holidays and users, with these rules:
  - Role changes go through `ChangeUserRole` (`syncRoles`), and admins can't demote themselves.
  - Agent profiles (categories and availability) go through `SyncAgentProfile`.
  - A category with tickets is deactivated instead of deleted.
  - Every holiday change flushes the cached holiday list, so new SLA calculations use it.

  T13 also exposes the public `categories.index` API for the create-ticket form.
- **Depends on:** T05 (lifecycle in place; `SlaCalculator` and `HolidayRepository` from T02), T04 (`CategoryPolicy`, `HolidayPolicy`, `UserPolicy`, `TicketPolicy::create`), T01 (models, factories, `Role`, `CategoryResource`, `UserSummaryResource`).
- **Wave:** 4b (parallel with T10, T11, T12).
- **TDs implemented:**
  - TD-010 / TD-120: roles assigned only by admin Actions via `syncRoles`, enums only, routes behind Policies
  - TD-011: admin-only configuration
  - TD-025: no hard delete of referenced rows; users are never deleted
  - TD-045 / TD-101: holidays are operational data, cached and flushed on change
  - TD-050: agent categories and availability feed auto-assignment
  - TD-053: transactions
  - TD-110: `CannotChangeOwnRole` domain error

## 2. Files

**Create**
- Actions:
  - `app/Actions/Admin/CreateCategory.php`, `UpdateCategory.php`, `DeleteCategory.php`
  - `app/Actions/Admin/CreateHoliday.php`, `UpdateHoliday.php`, `DeleteHoliday.php`
  - `app/Actions/Admin/CreateUser.php`, `UpdateUser.php`, `ChangeUserRole.php`, `SyncAgentProfile.php`
- Exception: `app/Exceptions/Domain/CannotChangeOwnRole.php`
- Read model: `app/Services/Admin/AdminDirectory.php`
- Requests, in `app/Http/Requests/Admin/`:
  - `StoreCategoryRequest.php`, `UpdateCategoryRequest.php`
  - `StoreHolidayRequest.php`, `UpdateHolidayRequest.php`, `IndexHolidaysRequest.php`
  - `StoreUserRequest.php`, `UpdateUserRequest.php`, `IndexUsersRequest.php`
- Resources: `app/Http/Resources/V1/HolidayResource.php`, `AdminUserResource.php`, `AdminCategoryResource.php`
- Controllers:
  - Web: `app/Http/Controllers/Web/Admin/CategoryController.php`, `HolidayController.php`, `UserController.php`
  - API: `app/Http/Controllers/Api/V1/Admin/CategoryController.php`, `HolidayController.php`, `UserController.php`
  - Public API: `app/Http/Controllers/Api/V1/CategoryController.php`
- Routes: `routes/web/admin.php`, `routes/api/v1/admin.php`
- Frontend:
  - Types: `resources/js/types/admin.ts`
  - Components: `resources/js/components/admin/AdminPagination.vue`, `CategoryForm.vue`, `HolidayForm.vue`, `UserForm.vue`
  - Pages: `resources/js/pages/admin/categories/{Index,Create,Edit}.vue`, `resources/js/pages/admin/holidays/{Index,Create,Edit}.vue`, `resources/js/pages/admin/users/{Index,Create,Edit}.vue`
  - Navigation: `resources/js/config/navigation/admin.ts`

**Test**
- `tests/Feature/Admin/CategoryActionsTest.php`
- `tests/Feature/Admin/HolidayActionsTest.php`
- `tests/Feature/Admin/UserActionsTest.php`
- `tests/Feature/Admin/CategoryManagementTest.php`
- `tests/Feature/Admin/HolidayManagementTest.php`
- `tests/Feature/Admin/UserManagementTest.php`
- `tests/Feature/Authorization/AdminAuthorizationTest.php`

**Modify:** none.

**Must not touch:**
- `app/Models/*` and `app/Services/Sla/*` (T02 owns `HolidayRepository`)
- `app/Policies/*` (T04)
- `CategoryResource` and `UserSummaryResource` (T01)
- `database/seeders/*`
- `routes/web.php`
- `resources/js/config/navigation.ts`
- the starter kit's `settings/*` pages

## 3. Interfaces

### Consumes

```php
// T01
enum Role: string { Admin, Agent, Customer; public function label(): string; }
User (HasRoles; fillable name, email, password, is_available; categories(): BelongsToMany; isAdmin(), isStaff())
Category (fillable name, slug, is_active; tickets(): HasMany; agents(); scopeActive)
Holiday (fillable date, name; cast date immutable_date)
CategoryResource, UserSummaryResource
abstract class DomainException extends \RuntimeException { status(): int; errorCode(): string; }
// T02
HolidayRepository::dates(): array; HolidayRepository::flush(): void   // cache key 'sla.holidays'
SlaCalculator::deadlinesFor(TicketPriority $priority, CarbonImmutable $start): SlaDeadlines
// T04
CategoryPolicy, HolidayPolicy, UserPolicy: viewAny, create, update, delete
TicketPolicy::create
```

### Produces (README §3, binding)

```php
Admin\SyncAgentProfile::handle(User $admin, User $agent, array $categoryIds, bool $isAvailable): User
Admin\ChangeUserRole::handle(User $admin, User $user, Role $role): User      // throws CannotChangeOwnRole
// Resources
HolidayResource, AdminUserResource
// Routes (web)
admin.categories.{index,create,store,edit,update,destroy}
admin.holidays.{index,create,store,edit,update,destroy}
admin.users.{index,create,store,edit,update}
// Routes (API, prefix api.v1.)
categories.index                                   GET /api/v1/categories   (any authenticated user who can create tickets)
admin.categories.{index,store,update,destroy}      /api/v1/admin/categories
admin.holidays.{index,store,update,destroy}        /api/v1/admin/holidays
admin.users.{index,store,update}                   /api/v1/admin/users
// Frontend
resources/js/config/navigation/admin.ts (default export NavItem[])
```

Task-internal additions (see Contract notes):
- the other eight Actions under `app/Actions/Admin`
- `CannotChangeOwnRole` (422, `cannot_change_own_role`)
- `AdminDirectory`
- `AdminCategoryResource`

Action signatures:

```php
Admin\CreateCategory::handle(array $data): Category
Admin\UpdateCategory::handle(Category $category, array $data): Category
Admin\DeleteCategory::handle(Category $category): bool                 // true deleted, false deactivated
Admin\CreateHoliday::handle(array $data): Holiday
Admin\UpdateHoliday::handle(Holiday $holiday, array $data): Holiday
Admin\DeleteHoliday::handle(Holiday $holiday): void
Admin\CreateUser::handle(User $admin, array $data): User
Admin\UpdateUser::handle(User $admin, User $user, array $data): User
```

## 4. Subtasks & mini-waves

| Subtask | Files | Depends on | Parallel-safe with |
|---|---|---|---|
| T13.1 Actions and `CannotChangeOwnRole` | 10 Actions, exception, 3 `*ActionsTest.php` | — | T13.2 |
| T13.2 Frontend | `types/admin.ts`, 4 components, 9 pages, `navigation/admin.ts` | — (props fixed in this file) | T13.1 |
| T13.3 Categories HTTP and public list | `AdminDirectory.php`, category requests, `AdminCategoryResource`, 3 category controllers, both route files (created), `CategoryManagementTest.php` | T13.1, T13.2 | — |
| T13.4 Holidays HTTP | holiday requests, `HolidayResource`, 2 holiday controllers, route files (append), `HolidayManagementTest.php` | T13.3 | — |
| T13.5 Users HTTP | user requests, `AdminUserResource`, 2 user controllers, route files (append), `UserManagementTest.php` | T13.4 | — |
| T13.6 Authorization matrix | `AdminAuthorizationTest.php` | T13.5 | — |

Mini-waves:
- **Wave A (parallel):** T13.1 ∥ T13.2. They touch disjoint files.
- **Wave B (sequential):** T13.3 → T13.4 → T13.5. All three append to `routes/web/admin.php` and `routes/api/v1/admin.php`.
- **Wave C:** T13.6.

**Inertia props** (fixed here; T13.2 builds against them; `ResourcePage<T>` = Laravel `{data, links, meta}`):

| Page | Props |
|---|---|
| `admin/categories/Index` | `categories: ResourcePage<AdminCategory>` |
| `admin/categories/Create` | none |
| `admin/categories/Edit` | `category: AdminCategory` |
| `admin/holidays/Index` | `holidays: ResourcePage<HolidayItem>`, `year: number` |
| `admin/holidays/Create` | none |
| `admin/holidays/Edit` | `holiday: HolidayItem` |
| `admin/users/Index` | `users: ResourcePage<AdminUser>`, `filters: { q: string \| null; role: string \| null }`, `roles: RoleOption[]` |
| `admin/users/Create` | `roles: RoleOption[]`, `categories: CategoryOption[]` |
| `admin/users/Edit` | `user: AdminUser`, `roles: RoleOption[]`, `categories: CategoryOption[]`, `isSelf: boolean` |

---

## 5. Subtasks

### T13.1 — Actions and `CannotChangeOwnRole`

- [ ] **Step 1: Write the failing test** `tests/Feature/Admin/CategoryActionsTest.php`.

```php
<?php

declare(strict_types=1);

use App\Actions\Admin\DeleteCategory;
use App\Models\Category;
use App\Models\Ticket;

it('deletes a category that no ticket references', function (): void {
    $category = Category::factory()->create();

    expect(app(DeleteCategory::class)->handle($category))->toBeTrue()
        ->and(Category::query()->whereKey($category->id)->exists())->toBeFalse();
});

it('deactivates instead of deleting a category that has tickets', function (): void {
    $category = Category::factory()->create(['is_active' => true]);
    Ticket::factory()->create(['category_id' => $category->id]);

    expect(app(DeleteCategory::class)->handle($category))->toBeFalse()
        ->and($category->fresh()?->is_active)->toBeFalse();
});
```

- [ ] **Step 2: Write the failing test** `tests/Feature/Admin/HolidayActionsTest.php`.

```php
<?php

declare(strict_types=1);

use App\Actions\Admin\CreateHoliday;
use App\Actions\Admin\DeleteHoliday;
use App\Actions\Admin\UpdateHoliday;
use App\Models\Holiday;
use App\Services\Sla\HolidayRepository;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    Cache::flush();
});

it('flushes the cached holiday list after every change', function (Closure $change): void {
    $holiday = Holiday::factory()->create(['date' => '2026-12-16', 'name' => 'Victory Day']);
    app(HolidayRepository::class)->dates();
    expect(Cache::has('sla.holidays'))->toBeTrue();

    $change($holiday);

    expect(Cache::has('sla.holidays'))->toBeFalse()
        ->and(app(HolidayRepository::class)->dates())->toBe(Holiday::query()->orderBy('date')->get()->map(
            fn (Holiday $h): string => $h->date->format('Y-m-d'),
        )->all());
})->with([
    'create' => [fn () => fn (Holiday $h) => app(CreateHoliday::class)->handle(['date' => '2026-02-21', 'name' => 'Language Day'])],
    'update' => [fn () => fn (Holiday $h) => app(UpdateHoliday::class)->handle($h, ['date' => '2026-12-17', 'name' => 'Victory Day'])],
    'delete' => [fn () => fn (Holiday $h) => app(DeleteHoliday::class)->handle($h)],
]);
```

  > Each dataset row is a closure that *returns* the change closure. Pest calls the outer one, and the test receives the inner one.

- [ ] **Step 3: Write the failing test** `tests/Feature/Admin/UserActionsTest.php`.

```php
<?php

declare(strict_types=1);

use App\Actions\Admin\ChangeUserRole;
use App\Actions\Admin\CreateUser;
use App\Actions\Admin\SyncAgentProfile;
use App\Actions\Admin\UpdateUser;
use App\Enums\Role;
use App\Exceptions\Domain\CannotChangeOwnRole;
use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->billing = Category::factory()->create();
    $this->hardware = Category::factory()->create();
});

it('changes a role with syncRoles, leaving exactly one role', function (): void {
    $user = User::factory()->customer()->create();

    app(ChangeUserRole::class)->handle($this->admin, $user, Role::Agent);

    expect($user->fresh()?->getRoleNames()->all())->toBe([Role::Agent->value]);
});

it('detaches agent categories when a user stops being an agent', function (): void {
    $agent = User::factory()->agent([$this->billing->id])->create();

    app(ChangeUserRole::class)->handle($this->admin, $agent, Role::Customer);

    expect($agent->fresh()?->categories()->count())->toBe(0);
});

it('refuses to let an admin change their own role', function (Role $role): void {
    app(ChangeUserRole::class)->handle($this->admin, $this->admin, $role);
})->with([Role::Agent, Role::Customer])->throws(CannotChangeOwnRole::class, 'You cannot change your own role.');

it('lets an admin keep their own admin role (no-op)', function (): void {
    app(ChangeUserRole::class)->handle($this->admin, $this->admin, Role::Admin);

    expect($this->admin->fresh()?->hasRole(Role::Admin->value))->toBeTrue();
});

it('syncs an agent\'s categories and availability', function (): void {
    $agent = User::factory()->agent([$this->billing->id])->create(['is_available' => true]);

    app(SyncAgentProfile::class)->handle($this->admin, $agent, [$this->hardware->id], false);

    $agent->refresh();
    expect($agent->categories()->pluck('categories.id')->all())->toBe([$this->hardware->id])
        ->and($agent->is_available)->toBeFalse();
});

it('creates a verified agent with a role and categories', function (): void {
    $user = app(CreateUser::class)->handle($this->admin, [
        'name' => 'New Agent',
        'email' => 'new.agent@example.com',
        'password' => 'S3cure-passw0rd!',
        'role' => Role::Agent->value,
        'category_ids' => [$this->billing->id, $this->hardware->id],
        'is_available' => true,
    ])->fresh();

    expect($user?->hasRole(Role::Agent->value))->toBeTrue()
        ->and($user?->categories()->count())->toBe(2)
        ->and($user?->email_verified_at)->not->toBeNull();
});

it('updates a user, keeping the password when none is given', function (): void {
    $user = User::factory()->customer()->create(['password' => 'old-password-123']);

    app(UpdateUser::class)->handle($this->admin, $user, [
        'name' => 'Renamed',
        'email' => 'renamed@example.com',
        'password' => null,
        'role' => Role::Agent->value,
        'category_ids' => [$this->billing->id],
        'is_available' => false,
    ]);

    $user->refresh();
    expect($user->name)->toBe('Renamed')
        ->and(Hash::check('old-password-123', $user->password))->toBeTrue()
        ->and($user->hasRole(Role::Agent->value))->toBeTrue()
        ->and($user->is_available)->toBeFalse()
        ->and($user->categories()->pluck('categories.id')->all())->toBe([$this->billing->id]);
});

it('rolls back the whole update when the admin tries to demote themselves', function (): void {
    expect(fn () => app(UpdateUser::class)->handle($this->admin, $this->admin, [
        'name' => 'Should not stick',
        'email' => $this->admin->email,
        'password' => null,
        'role' => Role::Customer->value,
    ]))->toThrow(CannotChangeOwnRole::class);

    expect($this->admin->fresh()?->name)->not->toBe('Should not stick')
        ->and($this->admin->fresh()?->hasRole(Role::Admin->value))->toBeTrue();
});
```

- [ ] **Step 4: Run all three and expect FAIL.**
  `./vendor/bin/pest tests/Feature/Admin/CategoryActionsTest.php tests/Feature/Admin/HolidayActionsTest.php tests/Feature/Admin/UserActionsTest.php`
  Expected: FAIL with `Error: Class "App\Actions\Admin\DeleteCategory" not found`. The other files fail the same way for their Actions.

- [ ] **Step 5: Create** `app/Exceptions/Domain/CannotChangeOwnRole.php`.

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use App\Models\User;

final class CannotChangeOwnRole extends DomainException
{
    public static function for(User $admin): self
    {
        return new self('You cannot change your own role.');
    }

    public function status(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'cannot_change_own_role';
    }
}
```

- [ ] **Step 6: Create** `app/Actions/Admin/CreateCategory.php`.

```php
<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\Category;

final class CreateCategory
{
    /** @param array{name: string, slug: string, is_active?: bool} $data */
    public function handle(array $data): Category
    {
        $category = new Category();
        $category->fill([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);
        $category->save();

        return $category;
    }
}
```

- [ ] **Step 7: Create** `app/Actions/Admin/UpdateCategory.php`.

```php
<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\Category;

final class UpdateCategory
{
    /** @param array{name: string, slug: string, is_active?: bool} $data */
    public function handle(Category $category, array $data): Category
    {
        $category->fill([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'is_active' => (bool) ($data['is_active'] ?? $category->is_active),
        ]);
        $category->save();

        return $category;
    }
}
```

- [ ] **Step 8: Create** `app/Actions/Admin/DeleteCategory.php`.

```php
<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\Category;
use Illuminate\Support\Facades\DB;

final class DeleteCategory
{
    /**
     * Tickets keep their category forever (FK restrict, TD-025), so a referenced category is deactivated instead.
     * Locking the category row blocks a concurrent ticket insert's FK check on it until we decide.
     *
     * @return bool true when deleted, false when deactivated
     */
    public function handle(Category $category): bool
    {
        return DB::transaction(function () use ($category): bool {
            $locked = Category::query()->whereKey($category->id)->lockForUpdate()->firstOrFail();

            if ($locked->tickets()->exists()) {
                $locked->is_active = false;
                $locked->save();
                $category->setRawAttributes($locked->getAttributes(), true);

                return false;
            }

            $locked->delete();

            return true;
        });
    }
}
```

- [ ] **Step 9: Create** `app/Actions/Admin/CreateHoliday.php`.

```php
<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\Holiday;
use App\Services\Sla\HolidayRepository;
use Illuminate\Support\Facades\DB;

final class CreateHoliday
{
    public function __construct(private readonly HolidayRepository $holidays) {}

    /** @param array{date: string, name: string} $data */
    public function handle(array $data): Holiday
    {
        return DB::transaction(function () use ($data): Holiday {
            $holiday = new Holiday();
            $holiday->fill(['date' => $data['date'], 'name' => $data['name']]);
            $holiday->save();

            // After commit: flushing earlier could let a concurrent request re-cache the old list forever.
            DB::afterCommit(fn () => $this->holidays->flush());

            return $holiday;
        });
    }
}
```

- [ ] **Step 10: Create** `app/Actions/Admin/UpdateHoliday.php`.

```php
<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\Holiday;
use App\Services\Sla\HolidayRepository;
use Illuminate\Support\Facades\DB;

final class UpdateHoliday
{
    public function __construct(private readonly HolidayRepository $holidays) {}

    /** @param array{date: string, name: string} $data */
    public function handle(Holiday $holiday, array $data): Holiday
    {
        return DB::transaction(function () use ($holiday, $data): Holiday {
            $holiday->fill(['date' => $data['date'], 'name' => $data['name']]);
            $holiday->save();

            DB::afterCommit(fn () => $this->holidays->flush());

            return $holiday;
        });
    }
}
```

- [ ] **Step 11: Create** `app/Actions/Admin/DeleteHoliday.php`.

```php
<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\Holiday;
use App\Services\Sla\HolidayRepository;
use Illuminate\Support\Facades\DB;

final class DeleteHoliday
{
    public function __construct(private readonly HolidayRepository $holidays) {}

    public function handle(Holiday $holiday): void
    {
        DB::transaction(function () use ($holiday): void {
            $holiday->delete();

            DB::afterCommit(fn () => $this->holidays->flush());
        });
    }
}
```

- [ ] **Step 12: Create** `app/Actions/Admin/ChangeUserRole.php`.

```php
<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\Role;
use App\Exceptions\Domain\CannotChangeOwnRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ChangeUserRole
{
    /** The only place roles are assigned (TD-010, TD-120). */
    public function handle(User $admin, User $user, Role $role): User
    {
        // Also guarantees the last admin can never lock everyone out.
        if ($admin->is($user) && $role !== Role::Admin) {
            throw CannotChangeOwnRole::for($admin);
        }

        return DB::transaction(function () use ($user, $role): User {
            $user->syncRoles([$role->value]);

            // Category links only mean something for agents (auto-assignment, TD-050).
            if ($role !== Role::Agent) {
                $user->categories()->detach();
            }

            return $user;
        });
    }
}
```

- [ ] **Step 13: Create** `app/Actions/Admin/SyncAgentProfile.php`.

```php
<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class SyncAgentProfile
{
    /** @param list<int|string> $categoryIds */
    public function handle(User $admin, User $agent, array $categoryIds, bool $isAvailable): User
    {
        return DB::transaction(function () use ($agent, $categoryIds, $isAvailable): User {
            $agent->categories()->sync(array_values(array_unique(array_map('intval', $categoryIds))));
            $agent->is_available = $isAvailable;
            $agent->save();

            return $agent;
        });
    }
}
```

- [ ] **Step 14: Create** `app/Actions/Admin/CreateUser.php`.

```php
<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class CreateUser
{
    public function __construct(
        private readonly ChangeUserRole $changeRole,
        private readonly SyncAgentProfile $syncAgent,
    ) {}

    /** @param array{name: string, email: string, password: string, role: string, category_ids?: list<int>, is_available?: bool} $data */
    public function handle(User $admin, array $data): User
    {
        return DB::transaction(function () use ($admin, $data): User {
            $isAvailable = (bool) ($data['is_available'] ?? true);

            $user = new User();
            $user->fill([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'is_available' => $isAvailable,
            ]);
            // An admin vouches for the address; there is no self-signup to verify.
            $user->email_verified_at = CarbonImmutable::now();
            $user->save();

            $role = Role::from($data['role']);
            $this->changeRole->handle($admin, $user, $role);

            if ($role === Role::Agent) {
                $this->syncAgent->handle($admin, $user, $data['category_ids'] ?? [], $isAvailable);
            }

            return $user;
        });
    }
}
```

- [ ] **Step 15: Create** `app/Actions/Admin/UpdateUser.php`.

```php
<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class UpdateUser
{
    public function __construct(
        private readonly ChangeUserRole $changeRole,
        private readonly SyncAgentProfile $syncAgent,
    ) {}

    /** @param array{name: string, email: string, password?: string|null, role: string, category_ids?: list<int>, is_available?: bool} $data */
    public function handle(User $admin, User $user, array $data): User
    {
        return DB::transaction(function () use ($admin, $user, $data): User {
            $role = Role::from($data['role']);

            // Role first: a refused self-demotion aborts before any field changes.
            $this->changeRole->handle($admin, $user, $role);

            $user->fill(['name' => $data['name'], 'email' => $data['email']]);
            if (($data['password'] ?? null) !== null && $data['password'] !== '') {
                $user->password = $data['password'];
            }
            $user->save();

            if ($role === Role::Agent) {
                $this->syncAgent->handle(
                    $admin,
                    $user,
                    $data['category_ids'] ?? [],
                    (bool) ($data['is_available'] ?? $user->is_available),
                );
            }

            return $user;
        });
    }
}
```

- [ ] **Step 16: Run all three and expect PASS.**
  `./vendor/bin/pest tests/Feature/Admin/CategoryActionsTest.php tests/Feature/Admin/HolidayActionsTest.php tests/Feature/Admin/UserActionsTest.php`
  Expected: PASS (14 tests: 2 category, 3 holiday dataset rows, 9 user including 2 dataset rows).

- [ ] **Step 17: Format and analyse.**
  `./vendor/bin/pint --dirty && ./vendor/bin/phpstan analyse --memory-limit=1G`
  Expected: `[OK] No errors`.

- [ ] **Step 18: Commit.**

```bash
git add app/Exceptions/Domain/CannotChangeOwnRole.php app/Actions/Admin \
  tests/Feature/Admin/CategoryActionsTest.php tests/Feature/Admin/HolidayActionsTest.php tests/Feature/Admin/UserActionsTest.php
git commit -m "feat(admin): add actions for categories, holidays, users and roles" --trailer "Refs: T13.1"
```

---
### T13.2 — Frontend

This task uses the starter-kit `button`, `card`, `input` and `label` components and `InputError.vue`. Selects and checkboxes are native, styled with Tailwind, so it needs no other shadcn components. Paths are literal, because the contract specifies no client route helper.

- [ ] **Step 1: Create** `resources/js/types/admin.ts`.

```ts
export interface ResourcePage<T> {
    data: T[];
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
}

export interface AdminCategory {
    id: number;
    name: string;
    slug: string;
    is_active: boolean;
    tickets_count?: number;
}

export interface HolidayItem {
    id: number;
    date: string;
    weekday: string;
    name: string;
}

export type RoleValue = 'admin' | 'agent' | 'customer';

export interface RoleOption {
    value: RoleValue;
    label: string;
}

export interface CategoryOption {
    id: number;
    name: string;
    is_active: boolean;
}

export interface AdminUser {
    id: number;
    name: string;
    email: string;
    role: RoleValue | null;
    is_available: boolean;
    last_assigned_at: string | null;
    categories?: { id: number; name: string }[];
    created_at: string | null;
}
```

- [ ] **Step 2: Create** `resources/js/components/admin/AdminPagination.vue`.

```vue
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import type { ResourcePage } from '@/types/admin';

defineProps<{
    links: ResourcePage<unknown>['links'];
    meta: ResourcePage<unknown>['meta'];
}>();
</script>

<template>
    <nav
        v-if="meta.last_page > 1"
        class="flex items-center justify-between gap-2 text-sm"
        aria-label="Pagination"
    >
        <span class="text-muted-foreground"
            >Page {{ meta.current_page }} of {{ meta.last_page }} ·
            {{ meta.total }} total</span
        >
        <div class="flex gap-2">
            <Button v-if="links.prev" variant="outline" size="sm" as-child>
                <Link :href="links.prev" preserve-scroll preserve-state
                    >Previous</Link
                >
            </Button>
            <Button v-if="links.next" variant="outline" size="sm" as-child>
                <Link :href="links.next" preserve-scroll preserve-state
                    >Next</Link
                >
            </Button>
        </div>
    </nav>
</template>
```

- [ ] **Step 3: Create** `resources/js/components/admin/CategoryForm.vue`. While the slug field is untouched, it follows the name.

```vue
<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { AdminCategory } from '@/types/admin';

const props = defineProps<{ category?: AdminCategory }>();

const form = useForm({
    name: props.category?.name ?? '',
    slug: props.category?.slug ?? '',
    is_active: props.category?.is_active ?? true,
});

const slugTouched = ref(props.category !== undefined);

function slugify(value: string): string {
    return value
        .toLowerCase()
        .normalize('NFKD')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

watch(
    () => form.name,
    (name: string): void => {
        if (!slugTouched.value) {
            form.slug = slugify(name);
        }
    },
);

function submit(): void {
    if (props.category) {
        form.patch(`/admin/categories/${props.category.id}`);
    } else {
        form.post('/admin/categories');
    }
}
</script>

<template>
    <form class="flex flex-col gap-4" @submit.prevent="submit">
        <div class="grid gap-2">
            <Label for="name">Name</Label>
            <Input id="name" v-model="form.name" required maxlength="100" />
            <InputError :message="form.errors.name" />
        </div>
        <div class="grid gap-2">
            <Label for="slug">Slug</Label>
            <Input
                id="slug"
                v-model="form.slug"
                required
                maxlength="100"
                @input="slugTouched = true"
            />
            <p class="text-xs text-muted-foreground">
                Lowercase letters, numbers and dashes. CSV imports refer to
                categories by slug.
            </p>
            <InputError :message="form.errors.slug" />
        </div>
        <label class="flex items-center gap-2 text-sm">
            <input
                v-model="form.is_active"
                type="checkbox"
                class="size-4 rounded border-input"
            />
            Active (offered when creating tickets)
        </label>
        <InputError :message="form.errors.is_active" />
        <div class="flex gap-2">
            <Button type="submit" :disabled="form.processing">{{
                category ? 'Save changes' : 'Create category'
            }}</Button>
            <Button variant="ghost" as-child
                ><Link href="/admin/categories">Cancel</Link></Button
            >
        </div>
    </form>
</template>
```

- [ ] **Step 4: Create** `resources/js/components/admin/HolidayForm.vue`.

```vue
<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { HolidayItem } from '@/types/admin';

const props = defineProps<{ holiday?: HolidayItem }>();

const form = useForm({
    date: props.holiday?.date ?? '',
    name: props.holiday?.name ?? '',
});

function submit(): void {
    if (props.holiday) {
        form.patch(`/admin/holidays/${props.holiday.id}`);
    } else {
        form.post('/admin/holidays');
    }
}
</script>

<template>
    <form class="flex flex-col gap-4" @submit.prevent="submit">
        <div class="grid gap-2">
            <Label for="date">Date</Label>
            <Input id="date" v-model="form.date" type="date" required />
            <InputError :message="form.errors.date" />
        </div>
        <div class="grid gap-2">
            <Label for="name">Name</Label>
            <Input id="name" v-model="form.name" required maxlength="100" />
            <InputError :message="form.errors.name" />
        </div>
        <p class="text-xs text-muted-foreground">
            New SLA deadlines skip this day. Deadlines already calculated for
            existing tickets are not changed.
        </p>
        <div class="flex gap-2">
            <Button type="submit" :disabled="form.processing">{{
                holiday ? 'Save changes' : 'Add holiday'
            }}</Button>
            <Button variant="ghost" as-child
                ><Link href="/admin/holidays">Cancel</Link></Button
            >
        </div>
    </form>
</template>
```

- [ ] **Step 5: Create** `resources/js/components/admin/UserForm.vue`.

```vue
<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type {
    AdminUser,
    CategoryOption,
    RoleOption,
    RoleValue,
} from '@/types/admin';

const props = defineProps<{
    user?: AdminUser;
    roles: RoleOption[];
    categories: CategoryOption[];
    isSelf?: boolean;
}>();

const form = useForm<{
    name: string;
    email: string;
    password: string;
    role: RoleValue;
    category_ids: number[];
    is_available: boolean;
}>({
    name: props.user?.name ?? '',
    email: props.user?.email ?? '',
    password: '',
    role: props.user?.role ?? 'customer',
    category_ids: props.user?.categories?.map((category) => category.id) ?? [],
    is_available: props.user?.is_available ?? true,
});

function submit(): void {
    if (props.user) {
        form.patch(`/admin/users/${props.user.id}`, {
            onSuccess: () => form.reset('password'),
        });
    } else {
        form.post('/admin/users');
    }
}
</script>

<template>
    <form class="flex flex-col gap-4" @submit.prevent="submit">
        <div class="grid gap-2">
            <Label for="name">Name</Label>
            <Input id="name" v-model="form.name" required maxlength="255" />
            <InputError :message="form.errors.name" />
        </div>
        <div class="grid gap-2">
            <Label for="email">Email</Label>
            <Input
                id="email"
                v-model="form.email"
                type="email"
                required
                maxlength="255"
            />
            <InputError :message="form.errors.email" />
        </div>
        <div class="grid gap-2">
            <Label for="password">{{
                user ? 'New password (leave blank to keep)' : 'Password'
            }}</Label>
            <Input
                id="password"
                v-model="form.password"
                type="password"
                autocomplete="new-password"
                :required="!user"
            />
            <InputError :message="form.errors.password" />
        </div>
        <div class="grid gap-2">
            <Label for="role">Role</Label>
            <select
                id="role"
                v-model="form.role"
                :disabled="isSelf"
                class="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs disabled:opacity-50"
            >
                <option
                    v-for="role in roles"
                    :key="role.value"
                    :value="role.value"
                >
                    {{ role.label }}
                </option>
            </select>
            <p v-if="isSelf" class="text-xs text-muted-foreground">
                You cannot change your own role.
            </p>
            <InputError :message="form.errors.role" />
        </div>

        <fieldset
            v-if="form.role === 'agent'"
            class="flex flex-col gap-3 rounded-lg border p-4"
        >
            <legend class="px-1 text-sm font-medium">Agent profile</legend>
            <label class="flex items-center gap-2 text-sm">
                <input
                    v-model="form.is_available"
                    type="checkbox"
                    class="size-4 rounded border-input"
                />
                Available for automatic assignment
            </label>
            <InputError :message="form.errors.is_available" />
            <div class="grid gap-2">
                <span class="text-sm">Categories</span>
                <p
                    v-if="categories.length === 0"
                    class="text-xs text-muted-foreground"
                >
                    No categories exist yet.
                </p>
                <label
                    v-for="category in categories"
                    :key="category.id"
                    class="flex items-center gap-2 text-sm"
                >
                    <input
                        v-model="form.category_ids"
                        type="checkbox"
                        :value="category.id"
                        class="size-4 rounded border-input"
                    />
                    {{ category.name }}
                    <span
                        v-if="!category.is_active"
                        class="text-xs text-muted-foreground"
                        >(inactive)</span
                    >
                </label>
                <InputError :message="form.errors.category_ids" />
            </div>
        </fieldset>

        <div class="flex gap-2">
            <Button type="submit" :disabled="form.processing">{{
                user ? 'Save changes' : 'Create user'
            }}</Button>
            <Button variant="ghost" as-child
                ><Link href="/admin/users">Cancel</Link></Button
            >
        </div>
    </form>
</template>
```

- [ ] **Step 6: Create** `resources/js/pages/admin/categories/Index.vue`.

```vue
<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AdminPagination from '@/components/admin/AdminPagination.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import type { AdminCategory, ResourcePage } from '@/types/admin';

defineProps<{ categories: ResourcePage<AdminCategory> }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Categories', href: '/admin/categories' }],
    },
});

function remove(category: AdminCategory): void {
    const inUse = (category.tickets_count ?? 0) > 0;
    const question = inUse
        ? `"${category.name}" has tickets, so it will be deactivated instead of deleted. Continue?`
        : `Delete "${category.name}"?`;

    if (window.confirm(question)) {
        router.delete(`/admin/categories/${category.id}`, {
            preserveScroll: true,
        });
    }
}
</script>

<template>
    <Head title="Categories" />
    <div class="flex flex-col gap-4 p-4">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold">Categories</h1>
            <Button as-child
                ><Link href="/admin/categories/create"
                    >New category</Link
                ></Button
            >
        </div>
        <Card>
            <CardContent class="flex flex-col gap-4 pt-6">
                <p
                    v-if="categories.data.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    No categories yet.
                </p>
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead
                            class="border-b text-xs text-muted-foreground uppercase"
                        >
                            <tr>
                                <th class="py-2 pr-4">Name</th>
                                <th class="py-2 pr-4">Slug</th>
                                <th class="py-2 pr-4">Status</th>
                                <th class="py-2 pr-4 text-right">Tickets</th>
                                <th class="py-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="category in categories.data"
                                :key="category.id"
                                class="border-b last:border-0"
                            >
                                <td class="py-2 pr-4 font-medium">
                                    {{ category.name }}
                                </td>
                                <td class="py-2 pr-4 font-mono text-xs">
                                    {{ category.slug }}
                                </td>
                                <td class="py-2 pr-4">
                                    <span
                                        :class="
                                            category.is_active
                                                ? 'text-emerald-600 dark:text-emerald-400'
                                                : 'text-muted-foreground'
                                        "
                                    >
                                        {{
                                            category.is_active
                                                ? 'Active'
                                                : 'Inactive'
                                        }}
                                    </span>
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {{ category.tickets_count ?? 0 }}
                                </td>
                                <td class="py-2 text-right">
                                    <div class="flex justify-end gap-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            as-child
                                        >
                                            <Link
                                                :href="`/admin/categories/${category.id}/edit`"
                                                >Edit</Link
                                            >
                                        </Button>
                                        <Button
                                            variant="destructive"
                                            size="sm"
                                            @click="remove(category)"
                                        >
                                            {{
                                                (category.tickets_count ?? 0) >
                                                0
                                                    ? 'Deactivate'
                                                    : 'Delete'
                                            }}
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <AdminPagination
                    :links="categories.links"
                    :meta="categories.meta"
                />
            </CardContent>
        </Card>
    </div>
</template>
```

- [ ] **Step 7: Create** `resources/js/pages/admin/categories/Create.vue`.

```vue
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import CategoryForm from '@/components/admin/CategoryForm.vue';
import { Card, CardContent } from '@/components/ui/card';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Categories', href: '/admin/categories' },
            { title: 'New', href: '/admin/categories/create' },
        ],
    },
});
</script>

<template>
    <Head title="New category" />
    <div class="flex max-w-xl flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">New category</h1>
        <Card
            ><CardContent class="pt-6"><CategoryForm /></CardContent
        ></Card>
    </div>
</template>
```

- [ ] **Step 8: Create** `resources/js/pages/admin/categories/Edit.vue`.

```vue
<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import { computed, watchEffect } from 'vue';
import CategoryForm from '@/components/admin/CategoryForm.vue';
import { Card, CardContent } from '@/components/ui/card';
import type { BreadcrumbItem } from '@/types';
import type { AdminCategory } from '@/types/admin';

const props = defineProps<{ category: AdminCategory }>();

const breadcrumbs = computed((): BreadcrumbItem[] => [
    { title: 'Categories', href: '/admin/categories' },
    {
        title: props.category.name,
        href: `/admin/categories/${props.category.id}/edit`,
    },
]);

// The breadcrumbs depend on props, so they're pushed to the default AppLayout (app.ts) reactively.
watchEffect(() => {
    setLayoutProps({ breadcrumbs: breadcrumbs.value });
});
</script>

<template>
    <Head :title="`Edit ${category.name}`" />
    <div class="flex max-w-xl flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">Edit category</h1>
        <Card
            ><CardContent class="pt-6"
                ><CategoryForm :category="category" /></CardContent
        ></Card>
    </div>
</template>
```

- [ ] **Step 9: Create** `resources/js/pages/admin/holidays/Index.vue`.

```vue
<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AdminPagination from '@/components/admin/AdminPagination.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { HolidayItem, ResourcePage } from '@/types/admin';

const props = defineProps<{
    holidays: ResourcePage<HolidayItem>;
    year: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Holidays', href: '/admin/holidays' }],
    },
});

function goToYear(year: number): void {
    router.get('/admin/holidays', { year }, { preserveState: true });
}

function remove(holiday: HolidayItem): void {
    if (window.confirm(`Delete "${holiday.name}" (${holiday.date})?`)) {
        router.delete(`/admin/holidays/${holiday.id}`, {
            preserveScroll: true,
        });
    }
}
</script>

<template>
    <Head title="Holidays" />
    <div class="flex flex-col gap-4 p-4">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold">Holidays</h1>
            <Button as-child
                ><Link href="/admin/holidays/create">Add holiday</Link></Button
            >
        </div>
        <Card>
            <CardHeader>
                <CardTitle class="flex items-center gap-2">
                    <Button
                        variant="ghost"
                        size="sm"
                        aria-label="Previous year"
                        @click="goToYear(props.year - 1)"
                        >‹</Button
                    >
                    {{ year }}
                    <Button
                        variant="ghost"
                        size="sm"
                        aria-label="Next year"
                        @click="goToYear(props.year + 1)"
                        >›</Button
                    >
                </CardTitle>
                <CardDescription
                    >Business days on these dates are skipped when SLA deadlines
                    are calculated.</CardDescription
                >
            </CardHeader>
            <CardContent class="flex flex-col gap-4">
                <p
                    v-if="holidays.data.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    No holidays in {{ year }}.
                </p>
                <table v-else class="w-full text-left text-sm">
                    <thead
                        class="border-b text-xs text-muted-foreground uppercase"
                    >
                        <tr>
                            <th class="py-2 pr-4">Date</th>
                            <th class="py-2 pr-4">Day</th>
                            <th class="py-2 pr-4">Name</th>
                            <th class="py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="holiday in holidays.data"
                            :key="holiday.id"
                            class="border-b last:border-0"
                        >
                            <td class="py-2 pr-4 font-mono text-xs">
                                {{ holiday.date }}
                            </td>
                            <td class="py-2 pr-4">{{ holiday.weekday }}</td>
                            <td class="py-2 pr-4">{{ holiday.name }}</td>
                            <td class="py-2 text-right">
                                <div class="flex justify-end gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        as-child
                                    >
                                        <Link
                                            :href="`/admin/holidays/${holiday.id}/edit`"
                                            >Edit</Link
                                        >
                                    </Button>
                                    <Button
                                        variant="destructive"
                                        size="sm"
                                        @click="remove(holiday)"
                                        >Delete</Button
                                    >
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <AdminPagination
                    :links="holidays.links"
                    :meta="holidays.meta"
                />
            </CardContent>
        </Card>
    </div>
</template>
```

- [ ] **Step 10: Create** `resources/js/pages/admin/holidays/Create.vue`.

```vue
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import HolidayForm from '@/components/admin/HolidayForm.vue';
import { Card, CardContent } from '@/components/ui/card';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Holidays', href: '/admin/holidays' },
            { title: 'New', href: '/admin/holidays/create' },
        ],
    },
});
</script>

<template>
    <Head title="Add holiday" />
    <div class="flex max-w-xl flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">Add holiday</h1>
        <Card
            ><CardContent class="pt-6"><HolidayForm /></CardContent
        ></Card>
    </div>
</template>
```

- [ ] **Step 11: Create** `resources/js/pages/admin/holidays/Edit.vue`.

```vue
<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import { computed, watchEffect } from 'vue';
import HolidayForm from '@/components/admin/HolidayForm.vue';
import { Card, CardContent } from '@/components/ui/card';
import type { BreadcrumbItem } from '@/types';
import type { HolidayItem } from '@/types/admin';

const props = defineProps<{ holiday: HolidayItem }>();

const breadcrumbs = computed((): BreadcrumbItem[] => [
    { title: 'Holidays', href: '/admin/holidays' },
    {
        title: props.holiday.name,
        href: `/admin/holidays/${props.holiday.id}/edit`,
    },
]);

// The breadcrumbs depend on props, so they're pushed to the default AppLayout (app.ts) reactively.
watchEffect(() => {
    setLayoutProps({ breadcrumbs: breadcrumbs.value });
});
</script>

<template>
    <Head :title="`Edit ${holiday.name}`" />
    <div class="flex max-w-xl flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">Edit holiday</h1>
        <Card
            ><CardContent class="pt-6"
                ><HolidayForm :holiday="holiday" /></CardContent
        ></Card>
    </div>
</template>
```

- [ ] **Step 12: Create** `resources/js/pages/admin/users/Index.vue`.

```vue
<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminPagination from '@/components/admin/AdminPagination.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import type { AdminUser, ResourcePage, RoleOption } from '@/types/admin';

const props = defineProps<{
    users: ResourcePage<AdminUser>;
    filters: { q: string | null; role: string | null };
    roles: RoleOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Users', href: '/admin/users' }],
    },
});

const q = ref(props.filters.q ?? '');
const role = ref(props.filters.role ?? '');

function search(): void {
    const params: Record<string, string> = {};
    if (q.value !== '') params.q = q.value;
    if (role.value !== '') params.role = role.value;
    router.get('/admin/users', params, { preserveState: true, replace: true });
}

function roleLabel(value: string | null): string {
    return props.roles.find((option) => option.value === value)?.label ?? '—';
}
</script>

<template>
    <Head title="Users" />
    <div class="flex flex-col gap-4 p-4">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold">Users</h1>
            <Button as-child
                ><Link href="/admin/users/create">New user</Link></Button
            >
        </div>
        <Card>
            <CardContent class="flex flex-col gap-4 pt-6">
                <form class="flex flex-wrap gap-2" @submit.prevent="search">
                    <Input
                        v-model="q"
                        type="search"
                        placeholder="Name or email starts with…"
                        class="max-w-xs"
                        aria-label="Search users"
                    />
                    <select
                        v-model="role"
                        class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                        aria-label="Role"
                    >
                        <option value="">All roles</option>
                        <option
                            v-for="option in roles"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                    <Button type="submit" variant="outline">Filter</Button>
                </form>
                <p
                    v-if="users.data.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    No users match.
                </p>
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead
                            class="border-b text-xs text-muted-foreground uppercase"
                        >
                            <tr>
                                <th class="py-2 pr-4">Name</th>
                                <th class="py-2 pr-4">Email</th>
                                <th class="py-2 pr-4">Role</th>
                                <th class="py-2 pr-4">Agent profile</th>
                                <th class="py-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="user in users.data"
                                :key="user.id"
                                class="border-b last:border-0"
                            >
                                <td class="py-2 pr-4 font-medium">
                                    {{ user.name }}
                                </td>
                                <td class="py-2 pr-4">{{ user.email }}</td>
                                <td class="py-2 pr-4">
                                    {{ roleLabel(user.role) }}
                                </td>
                                <td
                                    class="py-2 pr-4 text-xs text-muted-foreground"
                                >
                                    <template v-if="user.role === 'agent'">
                                        {{
                                            user.is_available
                                                ? 'Available'
                                                : 'Unavailable'
                                        }}
                                        ·
                                        {{
                                            user.categories
                                                ?.map(
                                                    (category) => category.name,
                                                )
                                                .join(', ') || 'no categories'
                                        }}
                                    </template>
                                    <template v-else>—</template>
                                </td>
                                <td class="py-2 text-right">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        as-child
                                    >
                                        <Link
                                            :href="`/admin/users/${user.id}/edit`"
                                            >Edit</Link
                                        >
                                    </Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <AdminPagination :links="users.links" :meta="users.meta" />
            </CardContent>
        </Card>
    </div>
</template>
```

- [ ] **Step 13: Create** `resources/js/pages/admin/users/Create.vue`.

```vue
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import UserForm from '@/components/admin/UserForm.vue';
import { Card, CardContent } from '@/components/ui/card';
import type { CategoryOption, RoleOption } from '@/types/admin';

defineProps<{ roles: RoleOption[]; categories: CategoryOption[] }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Users', href: '/admin/users' },
            { title: 'New', href: '/admin/users/create' },
        ],
    },
});
</script>

<template>
    <Head title="New user" />
    <div class="flex max-w-xl flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">New user</h1>
        <Card
            ><CardContent class="pt-6"
                ><UserForm
                    :roles="roles"
                    :categories="categories" /></CardContent
        ></Card>
    </div>
</template>
```

- [ ] **Step 14: Create** `resources/js/pages/admin/users/Edit.vue`.

```vue
<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import { computed, watchEffect } from 'vue';
import UserForm from '@/components/admin/UserForm.vue';
import { Card, CardContent } from '@/components/ui/card';
import type { BreadcrumbItem } from '@/types';
import type { AdminUser, CategoryOption, RoleOption } from '@/types/admin';

const props = defineProps<{
    user: AdminUser;
    roles: RoleOption[];
    categories: CategoryOption[];
    isSelf: boolean;
}>();

const breadcrumbs = computed((): BreadcrumbItem[] => [
    { title: 'Users', href: '/admin/users' },
    { title: props.user.name, href: `/admin/users/${props.user.id}/edit` },
]);

// The breadcrumbs depend on props, so they're pushed to the default AppLayout (app.ts) reactively.
watchEffect(() => {
    setLayoutProps({ breadcrumbs: breadcrumbs.value });
});
</script>

<template>
    <Head :title="`Edit ${user.name}`" />
    <div class="flex max-w-xl flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">Edit user</h1>
        <Card>
            <CardContent class="pt-6">
                <UserForm
                    :user="user"
                    :roles="roles"
                    :categories="categories"
                    :is-self="isSelf"
                />
            </CardContent>
        </Card>
    </div>
</template>
```

- [ ] **Step 15: Create** `resources/js/config/navigation/admin.ts`.

```ts
import { CalendarDays, Tags, Users } from '@lucide/vue';
import type { NavItem } from '@/config/navigation';

const items: NavItem[] = [
    {
        title: 'Categories',
        href: '/admin/categories',
        icon: Tags,
        order: 70,
        visible: (page) => page.auth.can.manageCategories,
    },
    {
        title: 'Holidays',
        href: '/admin/holidays',
        icon: CalendarDays,
        order: 71,
        visible: (page) => page.auth.can.manageHolidays,
    },
    {
        title: 'Users',
        href: '/admin/users',
        icon: Users,
        order: 72,
        visible: (page) => page.auth.can.manageUsers,
    },
];

export default items;
```

- [ ] **Step 16: Type-check and build.**
  `npm run build && npm run check && npx vue-tsc --noEmit`
  Expected: no type errors, and Vite prints `✓ built in …`.

- [ ] **Step 17: Commit.**

```bash
git add resources/js/types/admin.ts resources/js/components/admin resources/js/pages/admin/categories \
  resources/js/pages/admin/holidays resources/js/pages/admin/users resources/js/config/navigation/admin.ts
git commit -m "feat(admin): add category, holiday and user admin pages" --trailer "Refs: T13.2"
```

---
### T13.3 — Categories HTTP and the public category list

`AdminDirectory` is created here in full, with the holiday and user reads too. Controllers then stay query-free (TD-001), and T13.4 and T13.5 don't edit it again.

- [ ] **Step 1: Write the failing test** `tests/Feature/Admin/CategoryManagementTest.php`.

```php
<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
});

it('lists categories with their ticket counts', function (): void {
    $used = Category::factory()->create(['name' => 'Billing', 'slug' => 'billing']);
    Category::factory()->create(['name' => 'Access', 'slug' => 'access']);
    Ticket::factory()->count(2)->create(['category_id' => $used->id]);

    $this->actingAs($this->admin)
        ->get(route('admin.categories.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/categories/Index')
            ->has('categories.data', 2)
            ->where('categories.data.0.name', 'Access')
            ->where('categories.data.0.tickets_count', 0)
            ->where('categories.data.1.slug', 'billing')
            ->where('categories.data.1.tickets_count', 2));
});

it('renders the create and edit pages', function (): void {
    $category = Category::factory()->create();

    $this->actingAs($this->admin)->get(route('admin.categories.create'))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/categories/Create'));
    $this->actingAs($this->admin)->get(route('admin.categories.edit', $category))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/categories/Edit')
            ->where('category.id', $category->id));
});

it('creates a category', function (): void {
    $this->actingAs($this->admin)
        ->post(route('admin.categories.store'), ['name' => 'Billing', 'slug' => 'Billing', 'is_active' => true])
        ->assertRedirect(route('admin.categories.index'))
        ->assertSessionHas('success');

    expect(Category::query()->where('slug', 'billing')->exists())->toBeTrue();
});

it('validates the slug', function (array $payload, string $field): void {
    Category::factory()->create(['slug' => 'taken']);

    $this->actingAs($this->admin)
        ->post(route('admin.categories.store'), array_merge(['name' => 'X', 'slug' => 'ok-slug'], $payload))
        ->assertSessionHasErrors($field);
})->with([
    'duplicate slug' => [['slug' => 'taken'], 'slug'],
    'slug with spaces' => [['slug' => 'two words'], 'slug'],
    'slug with trailing dash' => [['slug' => 'bad-'], 'slug'],
]);

it('updates a category, allowing it to keep its own slug', function (): void {
    $category = Category::factory()->create(['name' => 'Billing', 'slug' => 'billing']);

    $this->actingAs($this->admin)
        ->patch(route('admin.categories.update', $category), ['name' => 'Billing & invoices', 'slug' => 'billing', 'is_active' => false])
        ->assertRedirect(route('admin.categories.index'))
        ->assertSessionHasNoErrors();

    expect($category->fresh()?->name)->toBe('Billing & invoices')
        ->and($category->fresh()?->is_active)->toBeFalse();
});

it('deletes an unused category and deactivates a used one', function (): void {
    $unused = Category::factory()->create();
    $used = Category::factory()->create(['is_active' => true]);
    Ticket::factory()->create(['category_id' => $used->id]);

    $this->actingAs($this->admin)->delete(route('admin.categories.destroy', $unused))
        ->assertRedirect(route('admin.categories.index'))
        ->assertSessionHas('success', 'Category deleted.');
    $this->actingAs($this->admin)->delete(route('admin.categories.destroy', $used))
        ->assertSessionHas('success', 'Category has tickets, so it was deactivated instead of deleted.');

    expect(Category::query()->whereKey($unused->id)->exists())->toBeFalse()
        ->and($used->fresh()?->is_active)->toBeFalse();
});

it('manages categories over the API', function (): void {
    Sanctum::actingAs($this->admin, ['*']);

    $id = $this->postJson(route('api.v1.admin.categories.store'), ['name' => 'Billing', 'slug' => 'billing'])
        ->assertCreated()
        ->assertJsonPath('data.slug', 'billing')
        ->assertJsonPath('data.is_active', true)
        ->json('data.id');

    $this->patchJson(route('api.v1.admin.categories.update', $id), ['name' => 'Billing 2', 'slug' => 'billing-2'])
        ->assertOk()
        ->assertJsonPath('data.slug', 'billing-2');

    $this->getJson(route('api.v1.admin.categories.index'))
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.tickets_count', 0);

    $this->deleteJson(route('api.v1.admin.categories.destroy', $id))->assertNoContent();
});

it('answers 200 with the deactivated category when the API delete hits a used category', function (): void {
    Sanctum::actingAs($this->admin, ['*']);
    $used = Category::factory()->create(['is_active' => true]);
    Ticket::factory()->create(['category_id' => $used->id]);

    $this->deleteJson(route('api.v1.admin.categories.destroy', $used))
        ->assertOk()
        ->assertJsonPath('data.is_active', false);
});

it('returns only active categories from the public list, to any role that can create tickets', function (string $state): void {
    Category::factory()->create(['name' => 'Billing', 'slug' => 'billing', 'is_active' => true]);
    Category::factory()->create(['name' => 'Legacy', 'slug' => 'legacy', 'is_active' => false]);

    $user = User::factory()->{$state}()->create();
    Sanctum::actingAs($user, ['*']);

    $this->getJson(route('api.v1.categories.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', 'billing');
})->with(['admin', 'agent', 'customer']);
```

- [ ] **Step 2: Run it and expect FAIL.**
  `./vendor/bin/pest tests/Feature/Admin/CategoryManagementTest.php`
  Expected: FAIL with `RouteNotFoundException: Route [admin.categories.index] not defined.`

- [ ] **Step 3: Create** `app/Services/Admin/AdminDirectory.php`.

```php
<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\Role;
use App\Models\Category;
use App\Models\Holiday;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/** Read queries for the admin screens, so controllers stay query-free (TD-001). */
final class AdminDirectory
{
    /** @return LengthAwarePaginator<int, Category> */
    public function categories(int $perPage = 20): LengthAwarePaginator
    {
        return Category::query()
            ->withCount('tickets')
            ->orderBy('name')
            ->paginate($perPage);
    }

    /** For the create-ticket form. @return Collection<int, Category> */
    public function activeCategories(): Collection
    {
        return Category::query()->active()->orderBy('name')->get();
    }

    /** Every category, for the agent-profile checkboxes. @return Collection<int, Category> */
    public function categoryOptions(): Collection
    {
        return Category::query()->orderBy('name')->get(['id', 'name', 'is_active']);
    }

    /** @return LengthAwarePaginator<int, Holiday> */
    public function holidays(int $year, int $perPage = 50): LengthAwarePaginator
    {
        return Holiday::query()
            ->whereBetween('date', [sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)])
            ->orderBy('date')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Prefix search, so the unique email index can serve email lookups.
     *
     * @param  array{q?: string|null, role?: string|null}  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function users(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $term = isset($filters['q']) && $filters['q'] !== '' ? addcslashes($filters['q'], '%_\\') : null;
        $role = isset($filters['role']) && $filters['role'] !== '' ? Role::from($filters['role']) : null;

        return User::query()
            ->with(['roles:id,name', 'categories:id,name'])
            ->when($term, function (Builder $query, string $term): void {
                $query->where(function (Builder $query) use ($term): void {
                    $query->where('name', 'like', $term.'%')->orWhere('email', 'like', $term.'%');
                });
            })
            ->when($role, fn (Builder $query, Role $role) => $query->role($role->value))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** @return list<array{value: string, label: string}> */
    public function roleOptions(): array
    {
        return array_map(
            static fn (Role $role): array => ['value' => $role->value, 'label' => $role->label()],
            Role::cases(),
        );
    }
}
```

- [ ] **Step 4: Create** `app/Http/Requests/Admin/StoreCategoryRequest.php`.

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
    public const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /** Authorized by the route's `can:` middleware (CategoryPolicy). */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('slug'))) {
            $this->merge(['slug' => strtolower(trim($this->input('slug')))]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:100', 'regex:'.self::SLUG_PATTERN, $this->uniqueSlug()],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function uniqueSlug(): \Illuminate\Validation\Rules\Unique
    {
        return Rule::unique('categories', 'slug');
    }
}
```

- [ ] **Step 5: Create** `app/Http/Requests/Admin/UpdateCategoryRequest.php`.

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

final class UpdateCategoryRequest extends StoreCategoryRequest
{
    protected function uniqueSlug(): Unique
    {
        return Rule::unique('categories', 'slug')->ignore($this->route('category'));
    }
}
```

  > `StoreCategoryRequest` is deliberately not `final`, so the update request can reuse it. It is the only non-final class in the task.

- [ ] **Step 6: Create** `app/Http/Resources/V1/AdminCategoryResource.php`.

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Category */
final class AdminCategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'is_active' => (bool) $this->is_active,
            'tickets_count' => $this->whenCounted('tickets', fn (): int => (int) $this->tickets_count, 0),
        ];
    }
}
```

- [ ] **Step 7: Create** `app/Http/Controllers/Web/Admin/CategoryController.php`.

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Actions\Admin\CreateCategory;
use App\Actions\Admin\DeleteCategory;
use App\Actions\Admin\UpdateCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCategoryRequest;
use App\Http\Requests\Admin\UpdateCategoryRequest;
use App\Http\Resources\V1\AdminCategoryResource;
use App\Models\Category;
use App\Services\Admin\AdminDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class CategoryController extends Controller
{
    public function index(Request $request, AdminDirectory $directory): Response
    {
        return Inertia::render('admin/categories/Index', [
            'categories' => AdminCategoryResource::collection($directory->categories())->response($request)->getData(true),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/categories/Create');
    }

    public function store(StoreCategoryRequest $request, CreateCategory $create): RedirectResponse
    {
        /** @var array{name: string, slug: string, is_active?: bool} $data */
        $data = $request->validated();
        $create->handle($data);

        return redirect()->route('admin.categories.index')->with('success', 'Category created.');
    }

    public function edit(Request $request, Category $category): Response
    {
        return Inertia::render('admin/categories/Edit', [
            'category' => AdminCategoryResource::make($category->loadCount('tickets'))->resolve($request),
        ]);
    }

    public function update(UpdateCategoryRequest $request, Category $category, UpdateCategory $update): RedirectResponse
    {
        /** @var array{name: string, slug: string, is_active?: bool} $data */
        $data = $request->validated();
        $update->handle($category, $data);

        return redirect()->route('admin.categories.index')->with('success', 'Category updated.');
    }

    public function destroy(Category $category, DeleteCategory $delete): RedirectResponse
    {
        $message = $delete->handle($category)
            ? 'Category deleted.'
            : 'Category has tickets, so it was deactivated instead of deleted.';

        return redirect()->route('admin.categories.index')->with('success', $message);
    }
}
```

- [ ] **Step 8: Create** `app/Http/Controllers/Api/V1/Admin/CategoryController.php`.

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Admin\CreateCategory;
use App\Actions\Admin\DeleteCategory;
use App\Actions\Admin\UpdateCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCategoryRequest;
use App\Http\Requests\Admin\UpdateCategoryRequest;
use App\Http\Resources\V1\AdminCategoryResource;
use App\Models\Category;
use App\Services\Admin\AdminDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class CategoryController extends Controller
{
    public function index(AdminDirectory $directory): AnonymousResourceCollection
    {
        return AdminCategoryResource::collection($directory->categories());
    }

    public function store(StoreCategoryRequest $request, CreateCategory $create): JsonResponse
    {
        /** @var array{name: string, slug: string, is_active?: bool} $data */
        $data = $request->validated();

        return AdminCategoryResource::make($create->handle($data)->loadCount('tickets'))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateCategoryRequest $request, Category $category, UpdateCategory $update): AdminCategoryResource
    {
        /** @var array{name: string, slug: string, is_active?: bool} $data */
        $data = $request->validated();

        return AdminCategoryResource::make($update->handle($category, $data)->loadCount('tickets'));
    }

    /** 204 when deleted; 200 with the deactivated category when tickets still reference it. */
    public function destroy(Category $category, DeleteCategory $delete): Response|AdminCategoryResource
    {
        if ($delete->handle($category)) {
            return response()->noContent();
        }

        return AdminCategoryResource::make($category->loadCount('tickets'));
    }
}
```

- [ ] **Step 9: Create** `app/Http/Controllers/Api/V1/CategoryController.php`.

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CategoryResource;
use App\Services\Admin\AdminDirectory;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Active categories for the create-ticket form. Authorized like ticket creation (TicketPolicy::create). */
final class CategoryController extends Controller
{
    public function index(AdminDirectory $directory): AnonymousResourceCollection
    {
        return CategoryResource::collection($directory->activeCategories());
    }
}
```

- [ ] **Step 10: Create** `routes/web/admin.php` with the category routes.

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\Web\Admin\CategoryController;
use App\Models\Category;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index')->can('viewAny', Category::class);
    Route::get('categories/create', [CategoryController::class, 'create'])->name('categories.create')->can('create', Category::class);
    Route::post('categories', [CategoryController::class, 'store'])->name('categories.store')->can('create', Category::class);
    Route::get('categories/{category}/edit', [CategoryController::class, 'edit'])->name('categories.edit')->can('update', 'category');
    Route::patch('categories/{category}', [CategoryController::class, 'update'])->name('categories.update')->can('update', 'category');
    Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy')->can('delete', 'category');

    // T13.4: holiday routes
    // T13.5: user routes
});
```

  > The two placeholder comments carry task references, as CLAUDE.md requires. T13.4 and T13.5 replace them.

- [ ] **Step 11: Create** `routes/api/v1/admin.php`.

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Models\Category;
use App\Models\Ticket;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    // Anyone who may create a ticket needs the category list for the form.
    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index')->can('create', Ticket::class);

    Route::prefix('admin')->name('admin.')->group(function (): void {
        Route::get('categories', [AdminCategoryController::class, 'index'])->name('categories.index')->can('viewAny', Category::class);
        Route::post('categories', [AdminCategoryController::class, 'store'])->name('categories.store')->can('create', Category::class);
        Route::patch('categories/{category}', [AdminCategoryController::class, 'update'])->name('categories.update')->can('update', 'category');
        Route::delete('categories/{category}', [AdminCategoryController::class, 'destroy'])->name('categories.destroy')->can('delete', 'category');

        // T13.4: holiday routes
        // T13.5: user routes
    });
});
```

- [ ] **Step 12: Run it and expect PASS.**
  `./vendor/bin/pest tests/Feature/Admin/CategoryManagementTest.php`
  Expected: PASS (13 tests, counting the 3 slug dataset rows and the 3 role dataset rows).

- [ ] **Step 13: Format and analyse.**
  `./vendor/bin/pint --dirty && ./vendor/bin/phpstan analyse --memory-limit=1G`
  Expected: `[OK] No errors`.

- [ ] **Step 14: Commit.**

```bash
git add app/Services/Admin/AdminDirectory.php app/Http/Requests/Admin/StoreCategoryRequest.php \
  app/Http/Requests/Admin/UpdateCategoryRequest.php app/Http/Resources/V1/AdminCategoryResource.php \
  app/Http/Controllers/Web/Admin/CategoryController.php app/Http/Controllers/Api/V1/Admin/CategoryController.php \
  app/Http/Controllers/Api/V1/CategoryController.php routes/web/admin.php routes/api/v1/admin.php \
  tests/Feature/Admin/CategoryManagementTest.php
git commit -m "feat(admin): add category admin and public category list" --trailer "Refs: T13.3"
```

---
### T13.4 — Holidays HTTP

- [ ] **Step 1: Write the failing test** `tests/Feature/Admin/HolidayManagementTest.php`.

```php
<?php

declare(strict_types=1);

use App\Enums\TicketPriority;
use App\Models\Holiday;
use App\Models\User;
use App\Services\Sla\BusinessCalendar;
use App\Services\Sla\HolidayRepository;
use App\Services\Sla\SlaCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-10-29 06:00:00', 'UTC'));
    $this->admin = User::factory()->admin()->create();
});

it('lists the holidays of the requested year, defaulting to the current one', function (): void {
    Holiday::factory()->create(['date' => '2026-12-16', 'name' => 'Victory Day']);
    Holiday::factory()->create(['date' => '2026-02-21', 'name' => 'Language Day']);
    Holiday::factory()->create(['date' => '2027-02-21', 'name' => 'Language Day']);

    $this->actingAs($this->admin)->get(route('admin.holidays.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/holidays/Index')
            ->where('year', 2026)
            ->has('holidays.data', 2)
            ->where('holidays.data.0.date', '2026-02-21')
            ->where('holidays.data.0.weekday', 'Saturday'));

    $this->actingAs($this->admin)->get(route('admin.holidays.index', ['year' => 2027]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('year', 2027)->has('holidays.data', 1));
});

it('renders the create and edit pages', function (): void {
    $holiday = Holiday::factory()->create(['date' => '2026-12-16']);

    $this->actingAs($this->admin)->get(route('admin.holidays.create'))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/holidays/Create'));
    $this->actingAs($this->admin)->get(route('admin.holidays.edit', $holiday))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/holidays/Edit')
            ->where('holiday.date', '2026-12-16'));
});

it('creates, updates and deletes a holiday', function (): void {
    $this->actingAs($this->admin)
        ->post(route('admin.holidays.store'), ['date' => '2026-12-16', 'name' => 'Victory Day'])
        ->assertRedirect(route('admin.holidays.index', ['year' => 2026]))
        ->assertSessionHas('success');
    $holiday = Holiday::query()->sole();

    $this->actingAs($this->admin)
        ->patch(route('admin.holidays.update', $holiday), ['date' => '2026-12-17', 'name' => 'Victory Day (observed)'])
        ->assertRedirect(route('admin.holidays.index', ['year' => 2026]));
    expect($holiday->fresh()?->date->format('Y-m-d'))->toBe('2026-12-17');

    $this->actingAs($this->admin)
        ->delete(route('admin.holidays.destroy', $holiday))
        ->assertRedirect(route('admin.holidays.index', ['year' => 2026]));
    expect(Holiday::query()->count())->toBe(0);
});

it('rejects a duplicate or malformed date', function (array $payload): void {
    Holiday::factory()->create(['date' => '2026-12-16']);

    $this->actingAs($this->admin)
        ->post(route('admin.holidays.store'), array_merge(['name' => 'Something'], $payload))
        ->assertSessionHasErrors('date');
})->with([
    'duplicate' => [['date' => '2026-12-16']],
    'not a date' => [['date' => '16/12/2026']],
    'missing' => [['date' => '']],
]);

it('lets a holiday keep its own date on update', function (): void {
    $holiday = Holiday::factory()->create(['date' => '2026-12-16', 'name' => 'Victory Day']);

    $this->actingAs($this->admin)
        ->patch(route('admin.holidays.update', $holiday), ['date' => '2026-12-16', 'name' => 'Bijoy Dibos'])
        ->assertSessionHasNoErrors();
});

it('makes new SLA deadlines skip a holiday added through the admin screen', function (): void {
    $start = CarbonImmutable::parse('2026-10-29 17:00', 'Asia/Dhaka'); // Thursday

    // Warm the holiday cache, as a running app would have.
    app(HolidayRepository::class)->dates();
    $before = app(SlaCalculator::class)->deadlinesFor(TicketPriority::Critical, $start);
    expect($before->dueAt->toDateTimeString())->toBe('2026-11-01 04:00:00'); // Sunday 10:00 Dhaka

    $this->actingAs($this->admin)
        ->post(route('admin.holidays.store'), ['date' => '2026-11-01', 'name' => 'Office closed'])
        ->assertSessionHasNoErrors();

    expect(Cache::has('sla.holidays'))->toBeFalse();

    // Simulate the next request: the calendar is rebuilt from the repository.
    app()->forgetScopedInstances();
    app()->forgetInstance(SlaCalculator::class);
    app()->forgetInstance(BusinessCalendar::class);

    $after = app(SlaCalculator::class)->deadlinesFor(TicketPriority::Critical, $start);
    expect($after->dueAt->toDateTimeString())->toBe('2026-11-02 04:00:00'); // Monday 10:00 Dhaka
});

it('manages holidays over the API', function (): void {
    Sanctum::actingAs($this->admin, ['*']);

    $id = $this->postJson(route('api.v1.admin.holidays.store'), ['date' => '2026-12-16', 'name' => 'Victory Day'])
        ->assertCreated()
        ->assertJsonPath('data.date', '2026-12-16')
        ->assertJsonPath('data.weekday', 'Wednesday')
        ->json('data.id');

    $this->getJson(route('api.v1.admin.holidays.index', ['year' => 2026]))
        ->assertOk()
        ->assertJsonPath('data.0.id', $id);

    $this->patchJson(route('api.v1.admin.holidays.update', $id), ['date' => '2026-12-17', 'name' => 'Victory Day'])
        ->assertOk()
        ->assertJsonPath('data.date', '2026-12-17');

    $this->deleteJson(route('api.v1.admin.holidays.destroy', $id))->assertNoContent();
    expect(Cache::has('sla.holidays'))->toBeFalse();
});
```

- [ ] **Step 2: Run it and expect FAIL.**
  `./vendor/bin/pest tests/Feature/Admin/HolidayManagementTest.php`
  Expected: FAIL with `RouteNotFoundException: Route [admin.holidays.index] not defined.`

- [ ] **Step 3: Create** `app/Http/Requests/Admin/StoreHolidayRequest.php`.

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class StoreHolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d', $this->uniqueDate()],
            'name' => ['required', 'string', 'max:100'],
        ];
    }

    protected function uniqueDate(): Unique
    {
        return Rule::unique('holidays', 'date');
    }
}
```

- [ ] **Step 4: Create** `app/Http/Requests/Admin/UpdateHolidayRequest.php`.

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

final class UpdateHolidayRequest extends StoreHolidayRequest
{
    protected function uniqueDate(): Unique
    {
        return Rule::unique('holidays', 'date')->ignore($this->route('holiday'));
    }
}
```

- [ ] **Step 5: Create** `app/Http/Requests/Admin/IndexHolidaysRequest.php`.

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

final class IndexHolidaysRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['year' => ['nullable', 'integer', 'between:2000,2100']];
    }

    /** Defaults to the current year in business time: holidays are business-calendar dates. */
    public function year(): int
    {
        return $this->filled('year')
            ? $this->integer('year')
            : CarbonImmutable::now((string) config('business_hours.timezone'))->year;
    }
}
```

- [ ] **Step 6: Create** `app/Http/Resources/V1/HolidayResource.php`.

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Holiday;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Holiday */
final class HolidayResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date->format('Y-m-d'),
            'weekday' => $this->date->format('l'),
            'name' => $this->name,
        ];
    }
}
```

- [ ] **Step 7: Create** `app/Http/Controllers/Web/Admin/HolidayController.php`.

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Actions\Admin\CreateHoliday;
use App\Actions\Admin\DeleteHoliday;
use App\Actions\Admin\UpdateHoliday;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexHolidaysRequest;
use App\Http\Requests\Admin\StoreHolidayRequest;
use App\Http\Requests\Admin\UpdateHolidayRequest;
use App\Http\Resources\V1\HolidayResource;
use App\Models\Holiday;
use App\Services\Admin\AdminDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class HolidayController extends Controller
{
    public function index(IndexHolidaysRequest $request, AdminDirectory $directory): Response
    {
        $year = $request->year();

        return Inertia::render('admin/holidays/Index', [
            'year' => $year,
            'holidays' => HolidayResource::collection($directory->holidays($year))->response($request)->getData(true),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/holidays/Create');
    }

    public function store(StoreHolidayRequest $request, CreateHoliday $create): RedirectResponse
    {
        /** @var array{date: string, name: string} $data */
        $data = $request->validated();
        $holiday = $create->handle($data)->refresh();

        return $this->backToYear($holiday, 'Holiday added. New SLA deadlines will skip it.');
    }

    public function edit(Request $request, Holiday $holiday): Response
    {
        return Inertia::render('admin/holidays/Edit', [
            'holiday' => HolidayResource::make($holiday)->resolve($request),
        ]);
    }

    public function update(UpdateHolidayRequest $request, Holiday $holiday, UpdateHoliday $update): RedirectResponse
    {
        /** @var array{date: string, name: string} $data */
        $data = $request->validated();

        return $this->backToYear($update->handle($holiday, $data)->refresh(), 'Holiday updated.');
    }

    public function destroy(Holiday $holiday, DeleteHoliday $delete): RedirectResponse
    {
        $delete->handle($holiday);

        return $this->backToYear($holiday, 'Holiday deleted.');
    }

    private function backToYear(Holiday $holiday, string $message): RedirectResponse
    {
        return redirect()
            ->route('admin.holidays.index', ['year' => (int) $holiday->date->format('Y')])
            ->with('success', $message);
    }
}
```

  > `store()` refreshes the created model so that `date` is cast before `backToYear()` reads it.

- [ ] **Step 8: Create** `app/Http/Controllers/Api/V1/Admin/HolidayController.php`.

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Admin\CreateHoliday;
use App\Actions\Admin\DeleteHoliday;
use App\Actions\Admin\UpdateHoliday;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexHolidaysRequest;
use App\Http\Requests\Admin\StoreHolidayRequest;
use App\Http\Requests\Admin\UpdateHolidayRequest;
use App\Http\Resources\V1\HolidayResource;
use App\Models\Holiday;
use App\Services\Admin\AdminDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class HolidayController extends Controller
{
    public function index(IndexHolidaysRequest $request, AdminDirectory $directory): AnonymousResourceCollection
    {
        return HolidayResource::collection($directory->holidays($request->year()));
    }

    public function store(StoreHolidayRequest $request, CreateHoliday $create): JsonResponse
    {
        /** @var array{date: string, name: string} $data */
        $data = $request->validated();

        return HolidayResource::make($create->handle($data)->refresh())->response()->setStatusCode(201);
    }

    public function update(UpdateHolidayRequest $request, Holiday $holiday, UpdateHoliday $update): HolidayResource
    {
        /** @var array{date: string, name: string} $data */
        $data = $request->validated();

        return HolidayResource::make($update->handle($holiday, $data)->refresh());
    }

    public function destroy(Holiday $holiday, DeleteHoliday $delete): Response
    {
        $delete->handle($holiday);

        return response()->noContent();
    }
}
```

- [ ] **Step 9: Add the web holiday routes.** In `routes/web/admin.php`, add `use App\Http\Controllers\Web\Admin\HolidayController;` and `use App\Models\Holiday;` to the imports, then replace the line `    // T13.4: holiday routes` with:

```php
    Route::get('holidays', [HolidayController::class, 'index'])->name('holidays.index')->can('viewAny', Holiday::class);
    Route::get('holidays/create', [HolidayController::class, 'create'])->name('holidays.create')->can('create', Holiday::class);
    Route::post('holidays', [HolidayController::class, 'store'])->name('holidays.store')->can('create', Holiday::class);
    Route::get('holidays/{holiday}/edit', [HolidayController::class, 'edit'])->name('holidays.edit')->can('update', 'holiday');
    Route::patch('holidays/{holiday}', [HolidayController::class, 'update'])->name('holidays.update')->can('update', 'holiday');
    Route::delete('holidays/{holiday}', [HolidayController::class, 'destroy'])->name('holidays.destroy')->can('delete', 'holiday');
```

- [ ] **Step 10: Add the API holiday routes.** In `routes/api/v1/admin.php`, add `use App\Http\Controllers\Api\V1\Admin\HolidayController as AdminHolidayController;` and `use App\Models\Holiday;`, then replace the line `        // T13.4: holiday routes` with:

```php
        Route::get('holidays', [AdminHolidayController::class, 'index'])->name('holidays.index')->can('viewAny', Holiday::class);
        Route::post('holidays', [AdminHolidayController::class, 'store'])->name('holidays.store')->can('create', Holiday::class);
        Route::patch('holidays/{holiday}', [AdminHolidayController::class, 'update'])->name('holidays.update')->can('update', 'holiday');
        Route::delete('holidays/{holiday}', [AdminHolidayController::class, 'destroy'])->name('holidays.destroy')->can('delete', 'holiday');
```

- [ ] **Step 11: Run it and expect PASS.**
  `./vendor/bin/pest tests/Feature/Admin/HolidayManagementTest.php tests/Feature/Admin/HolidayActionsTest.php`
  Expected: PASS (12 tests: 9 management, counting the 3 date dataset rows, and 3 action dataset rows).

- [ ] **Step 12: Format and analyse.**
  `./vendor/bin/pint --dirty && ./vendor/bin/phpstan analyse --memory-limit=1G`
  Expected: `[OK] No errors`.

- [ ] **Step 13: Commit.**

```bash
git add app/Http/Requests/Admin/StoreHolidayRequest.php app/Http/Requests/Admin/UpdateHolidayRequest.php \
  app/Http/Requests/Admin/IndexHolidaysRequest.php app/Http/Resources/V1/HolidayResource.php \
  app/Http/Controllers/Web/Admin/HolidayController.php app/Http/Controllers/Api/V1/Admin/HolidayController.php \
  routes/web/admin.php routes/api/v1/admin.php tests/Feature/Admin/HolidayManagementTest.php
git commit -m "feat(admin): add holiday admin with SLA cache invalidation" --trailer "Refs: T13.4"
```

---
### T13.5 — Users HTTP

- [ ] **Step 1: Write the failing test** `tests/Feature/Admin/UserManagementTest.php`.

```php
<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Category;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create(['name' => 'Ada Admin', 'email' => 'ada@example.com']);
    $this->category = Category::factory()->create(['name' => 'Billing']);
});

it('lists users with role and agent profile, filtered by search and role', function (): void {
    User::factory()->agent([$this->category->id])->create(['name' => 'Bob Agent', 'email' => 'bob@example.com']);
    User::factory()->customer()->create(['name' => 'Cara Customer', 'email' => 'cara@example.com']);

    $this->actingAs($this->admin)->get(route('admin.users.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/users/Index')
            ->has('users.data', 3)
            ->where('users.data.1.name', 'Bob Agent')
            ->where('users.data.1.role', 'agent')
            ->where('users.data.1.categories.0.name', 'Billing')
            ->has('roles', 3));

    $this->actingAs($this->admin)->get(route('admin.users.index', ['q' => 'car']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('users.data', 1)->where('filters.q', 'car'));

    $this->actingAs($this->admin)->get(route('admin.users.index', ['role' => 'agent']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('users.data', 1)->where('users.data.0.email', 'bob@example.com'));
});

it('treats LIKE wildcards in the search as literal characters', function (): void {
    $this->actingAs($this->admin)->get(route('admin.users.index', ['q' => '%']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('users.data', 0));
});

it('renders the create and edit pages, flagging self-edit', function (): void {
    $this->actingAs($this->admin)->get(route('admin.users.create'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/users/Create')
            ->has('roles', 3)
            ->has('categories', 1));

    $this->actingAs($this->admin)->get(route('admin.users.edit', $this->admin))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/users/Edit')
            ->where('user.id', $this->admin->id)
            ->where('isSelf', true));
});

it('creates a user from the web form', function (): void {
    $this->actingAs($this->admin)
        ->post(route('admin.users.store'), [
            'name' => 'New Agent',
            'email' => 'new.agent@example.com',
            'password' => 'S3cure-passw0rd!',
            'role' => 'agent',
            'category_ids' => [$this->category->id],
            'is_available' => false,
        ])
        ->assertRedirect(route('admin.users.index'))
        ->assertSessionHas('success');

    expect(User::query()->where('email', 'new.agent@example.com')->exists())->toBeTrue();
});

it('validates new users', function (array $payload, string $field): void {
    User::factory()->customer()->create(['email' => 'taken@example.com']);

    $this->actingAs($this->admin)
        ->post(route('admin.users.store'), array_merge([
            'name' => 'Someone',
            'email' => 'someone@example.com',
            'password' => 'S3cure-passw0rd!',
            'role' => 'customer',
        ], $payload))
        ->assertSessionHasErrors($field);
})->with([
    'duplicate email' => [['email' => 'taken@example.com'], 'email'],
    'unknown role' => [['role' => 'superuser'], 'role'],
    'unknown category' => [['role' => 'agent', 'category_ids' => [999999]], 'category_ids.0'],
]);

it('does not let an admin demote themselves on the web', function (): void {
    $this->actingAs($this->admin)
        ->patch(route('admin.users.update', $this->admin), [
            'name' => 'Ada Admin',
            'email' => 'ada@example.com',
            'role' => 'agent',
        ])
        ->assertSessionHasErrors(['role' => 'You cannot remove your own administrator role.']);

    expect($this->admin->fresh()?->hasRole(Role::Admin->value))->toBeTrue();
});

it('does not let an admin demote themselves over the API', function (): void {
    Sanctum::actingAs($this->admin, ['*']);

    $this->patchJson(route('api.v1.admin.users.update', $this->admin), [
        'name' => 'Ada Admin',
        'email' => 'ada@example.com',
        'role' => 'customer',
    ])->assertUnprocessable()->assertJsonValidationErrors('role');

    expect($this->admin->fresh()?->hasRole(Role::Admin->value))->toBeTrue();
});

it('lets an admin edit their own name while keeping the admin role', function (): void {
    $this->actingAs($this->admin)
        ->patch(route('admin.users.update', $this->admin), [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'role' => 'admin',
        ])
        ->assertSessionHasNoErrors();

    expect($this->admin->fresh()?->name)->toBe('Ada Lovelace');
});

it('manages users over the API', function (): void {
    Sanctum::actingAs($this->admin, ['*']);

    $id = $this->postJson(route('api.v1.admin.users.store'), [
        'name' => 'Api Agent',
        'email' => 'api.agent@example.com',
        'password' => 'S3cure-passw0rd!',
        'role' => 'agent',
        'category_ids' => [$this->category->id],
    ])->assertCreated()
        ->assertJsonPath('data.role', 'agent')
        ->assertJsonPath('data.categories.0.id', $this->category->id)
        ->assertJsonMissingPath('data.password')
        ->json('data.id');

    $this->patchJson(route('api.v1.admin.users.update', $id), [
        'name' => 'Api Agent',
        'email' => 'api.agent@example.com',
        'role' => 'agent',
        'category_ids' => [],
        'is_available' => false,
    ])->assertOk()
        ->assertJsonPath('data.is_available', false)
        ->assertJsonPath('data.categories', []);

    $this->getJson(route('api.v1.admin.users.index', ['role' => 'agent']))
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
});
```

- [ ] **Step 2: Run it and expect FAIL.**
  `./vendor/bin/pest tests/Feature/Admin/UserManagementTest.php`
  Expected: FAIL with `RouteNotFoundException: Route [admin.users.index] not defined.`

- [ ] **Step 3: Create** `app/Http/Requests/Admin/IndexUsersRequest.php`.

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::enum(Role::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array{q: string|null, role: string|null} */
    public function filters(): array
    {
        return [
            'q' => $this->filled('q') ? (string) $this->input('q') : null,
            'role' => $this->filled('role') ? (string) $this->input('role') : null,
        ];
    }
}
```

- [ ] **Step 4: Create** `app/Http/Requests/Admin/StoreUserRequest.php`.

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::defaults()],
            'role' => ['required', Rule::enum(Role::class)],
            'category_ids' => ['sometimes', 'array'],
            'category_ids.*' => ['integer', 'distinct', Rule::exists('categories', 'id')],
            'is_available' => ['sometimes', 'boolean'],
        ];
    }
}
```

- [ ] **Step 5: Create** `app/Http/Requests/Admin/UpdateUserRequest.php`.

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\Role;
use App\Models\User;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class UpdateUserRequest extends StoreUserRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('user'))],
            'password' => ['nullable', 'string', Password::defaults()],
            // A friendly field error; ChangeUserRole enforces the same rule for every caller.
            'role' => ['required', Rule::enum(Role::class), $this->notSelfDemotion(...)],
        ]);
    }

    private function notSelfDemotion(string $attribute, mixed $value, Closure $fail): void
    {
        $target = $this->route('user');
        $actor = $this->user();

        if ($target instanceof User && $actor instanceof User && $actor->is($target) && $value !== Role::Admin->value) {
            $fail('You cannot remove your own administrator role.');
        }
    }
}
```

- [ ] **Step 6: Create** `app/Http/Resources/V1/AdminUserResource.php`.

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Category;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Explicit field list: never the password, remember_token or raw role pivots (TD-120).
 *
 * @mixin User
 */
final class AdminUserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->relationLoaded('roles') ? $this->roles->first()?->name : null,
            'is_available' => (bool) $this->is_available,
            'last_assigned_at' => $this->last_assigned_at?->toIso8601String(),
            'categories' => $this->whenLoaded('categories', fn (): array => $this->categories
                ->map(static fn (Category $category): array => ['id' => $category->id, 'name' => $category->name])
                ->values()
                ->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 7: Create** `app/Http/Controllers/Web/Admin/UserController.php`.

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Actions\Admin\CreateUser;
use App\Actions\Admin\UpdateUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexUsersRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\V1\AdminUserResource;
use App\Models\Category;
use App\Models\User;
use App\Services\Admin\AdminDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class UserController extends Controller
{
    public function __construct(private readonly AdminDirectory $directory) {}

    public function index(IndexUsersRequest $request): Response
    {
        $filters = $request->filters();

        return Inertia::render('admin/users/Index', [
            'users' => AdminUserResource::collection($this->directory->users($filters))->response($request)->getData(true),
            'filters' => $filters,
            'roles' => $this->directory->roleOptions(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/users/Create', [
            'roles' => $this->directory->roleOptions(),
            'categories' => $this->categoryOptions(),
        ]);
    }

    public function store(StoreUserRequest $request, CreateUser $create): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        /** @var array{name: string, email: string, password: string, role: string, category_ids?: list<int>, is_available?: bool} $data */
        $data = $request->validated();

        $create->handle($admin, $data);

        return redirect()->route('admin.users.index')->with('success', 'User created.');
    }

    public function edit(Request $request, User $user): Response
    {
        return Inertia::render('admin/users/Edit', [
            'user' => AdminUserResource::make($user->load(['roles:id,name', 'categories:id,name']))->resolve($request),
            'roles' => $this->directory->roleOptions(),
            'categories' => $this->categoryOptions(),
            'isSelf' => $request->user()?->is($user) === true,
        ]);
    }

    public function update(UpdateUserRequest $request, User $user, UpdateUser $update): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        /** @var array{name: string, email: string, password?: string|null, role: string, category_ids?: list<int>, is_available?: bool} $data */
        $data = $request->validated();

        $update->handle($admin, $user, $data);

        return redirect()->route('admin.users.index')->with('success', 'User updated.');
    }

    /** @return list<array{id: int, name: string, is_active: bool}> */
    private function categoryOptions(): array
    {
        return $this->directory->categoryOptions()
            ->map(static fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'is_active' => (bool) $category->is_active,
            ])
            ->values()
            ->all();
    }
}
```

- [ ] **Step 8: Create** `app/Http/Controllers/Api/V1/Admin/UserController.php`.

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Admin\CreateUser;
use App\Actions\Admin\UpdateUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexUsersRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\V1\AdminUserResource;
use App\Models\User;
use App\Services\Admin\AdminDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class UserController extends Controller
{
    public function index(IndexUsersRequest $request, AdminDirectory $directory): AnonymousResourceCollection
    {
        return AdminUserResource::collection($directory->users($request->filters()));
    }

    public function store(StoreUserRequest $request, CreateUser $create): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        /** @var array{name: string, email: string, password: string, role: string, category_ids?: list<int>, is_available?: bool} $data */
        $data = $request->validated();

        $user = $create->handle($admin, $data)->load(['roles:id,name', 'categories:id,name']);

        return AdminUserResource::make($user)->response()->setStatusCode(201);
    }

    public function update(UpdateUserRequest $request, User $user, UpdateUser $update): AdminUserResource
    {
        /** @var User $admin */
        $admin = $request->user();
        /** @var array{name: string, email: string, password?: string|null, role: string, category_ids?: list<int>, is_available?: bool} $data */
        $data = $request->validated();

        return AdminUserResource::make($update->handle($admin, $user, $data)->load(['roles:id,name', 'categories:id,name']));
    }
}
```

- [ ] **Step 9: Add the web user routes.** In `routes/web/admin.php`, add `use App\Http\Controllers\Web\Admin\UserController;` and `use App\Models\User;`, then replace `    // T13.5: user routes` with the block below. There is no destroy route: users are deactivated, never deleted (TD-025).

```php
    Route::get('users', [UserController::class, 'index'])->name('users.index')->can('viewAny', User::class);
    Route::get('users/create', [UserController::class, 'create'])->name('users.create')->can('create', User::class);
    Route::post('users', [UserController::class, 'store'])->name('users.store')->can('create', User::class);
    Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit')->can('update', 'user');
    Route::patch('users/{user}', [UserController::class, 'update'])->name('users.update')->can('update', 'user');
```

- [ ] **Step 10: Add the API user routes.** In `routes/api/v1/admin.php`, add `use App\Http\Controllers\Api\V1\Admin\UserController as AdminUserController;` and `use App\Models\User;`, then replace `        // T13.5: user routes` with:

```php
        Route::get('users', [AdminUserController::class, 'index'])->name('users.index')->can('viewAny', User::class);
        Route::post('users', [AdminUserController::class, 'store'])->name('users.store')->can('create', User::class);
        Route::patch('users/{user}', [AdminUserController::class, 'update'])->name('users.update')->can('update', 'user');
```

- [ ] **Step 11: Run it and expect PASS.**
  `./vendor/bin/pest tests/Feature/Admin`
  Expected: PASS (all Admin tests, of which UserManagementTest contributes 11, counting 3 dataset rows).

- [ ] **Step 12: Format and analyse.**
  `./vendor/bin/pint --dirty && ./vendor/bin/phpstan analyse --memory-limit=1G`
  Expected: `[OK] No errors`.

- [ ] **Step 13: Commit.**

```bash
git add app/Http/Requests/Admin/IndexUsersRequest.php app/Http/Requests/Admin/StoreUserRequest.php \
  app/Http/Requests/Admin/UpdateUserRequest.php app/Http/Resources/V1/AdminUserResource.php \
  app/Http/Controllers/Web/Admin/UserController.php app/Http/Controllers/Api/V1/Admin/UserController.php \
  routes/web/admin.php routes/api/v1/admin.php tests/Feature/Admin/UserManagementTest.php
git commit -m "feat(admin): add user admin with role changes and agent profiles" --trailer "Refs: T13.5"
```

---

### T13.6 — Authorization matrix

- [ ] **Step 1: Write the test** `tests/Feature/Authorization/AdminAuthorizationTest.php`. It uses T04's `Tests\Support\AuthorizationMatrix`: `/api/` URIs go as JSON with Sanctum, and web URIs go through the session.

```php
<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Category;
use App\Models\Holiday;
use App\Models\User;
use Tests\Support\AuthorizationMatrix;

beforeEach(function (): void {
    $this->category = Category::factory()->create();
    $this->holiday = Holiday::factory()->create(['date' => '2026-12-16']);
    $this->target = User::factory()->customer()->create();
});

/**
 * Every admin route, web and API: [method, uri, guest status].
 *
 * @return list<array{0: string, 1: string, 2: int}>
 */
function adminAuthorizationRows(Category $category, Holiday $holiday, User $user): array
{
    return [
        ['GET', route('admin.categories.index'), 302],
        ['GET', route('admin.categories.create'), 302],
        ['POST', route('admin.categories.store'), 302],
        ['GET', route('admin.categories.edit', $category), 302],
        ['PATCH', route('admin.categories.update', $category), 302],
        ['DELETE', route('admin.categories.destroy', $category), 302],
        ['GET', route('admin.holidays.index'), 302],
        ['GET', route('admin.holidays.create'), 302],
        ['POST', route('admin.holidays.store'), 302],
        ['GET', route('admin.holidays.edit', $holiday), 302],
        ['PATCH', route('admin.holidays.update', $holiday), 302],
        ['DELETE', route('admin.holidays.destroy', $holiday), 302],
        ['GET', route('admin.users.index'), 302],
        ['GET', route('admin.users.create'), 302],
        ['POST', route('admin.users.store'), 302],
        ['GET', route('admin.users.edit', $user), 302],
        ['PATCH', route('admin.users.update', $user), 302],
        ['GET', route('api.v1.admin.categories.index'), 401],
        ['POST', route('api.v1.admin.categories.store'), 401],
        ['PATCH', route('api.v1.admin.categories.update', $category), 401],
        ['DELETE', route('api.v1.admin.categories.destroy', $category), 401],
        ['GET', route('api.v1.admin.holidays.index'), 401],
        ['POST', route('api.v1.admin.holidays.store'), 401],
        ['PATCH', route('api.v1.admin.holidays.update', $holiday), 401],
        ['DELETE', route('api.v1.admin.holidays.destroy', $holiday), 401],
        ['GET', route('api.v1.admin.users.index'), 401],
        ['POST', route('api.v1.admin.users.store'), 401],
        ['PATCH', route('api.v1.admin.users.update', $user), 401],
    ];
}

it('opens every admin page and API read for admins', function (): void {
    $matrix = AuthorizationMatrix::for($this);
    $matrix->actingAsRole(Role::Admin);

    foreach (adminAuthorizationRows($this->category, $this->holiday, $this->target) as [$method, $uri]) {
        if ($method === 'GET') {
            $matrix->expectAccess($method, $uri, 200);
        }
    }
});

it('forbids non-admins on every admin route, web and API, and changes nothing', function (Role $role): void {
    $matrix = AuthorizationMatrix::for($this);
    $matrix->actingAsRole($role);

    foreach (adminAuthorizationRows($this->category, $this->holiday, $this->target) as [$method, $uri]) {
        $matrix->expectAccess($method, $uri, 403);
    }

    expect(Category::query()->whereKey($this->category->id)->exists())->toBeTrue()
        ->and(Holiday::query()->whereKey($this->holiday->id)->exists())->toBeTrue();
})->with([Role::Agent, Role::Customer]);

it('rejects an agent write before validation runs', function (): void {
    $matrix = AuthorizationMatrix::for($this);
    $matrix->actingAsRole(Role::Agent);

    // 403, not 422: the route's `can:` middleware runs before the FormRequest.
    $matrix->expectAccess('POST', route('api.v1.admin.users.store'), 403, ['role' => 'admin']);
});

it('rejects guests everywhere, including the public category list', function (): void {
    $matrix = AuthorizationMatrix::for($this)->asGuest();

    foreach (adminAuthorizationRows($this->category, $this->holiday, $this->target) as [$method, $uri, $guestStatus]) {
        $response = $matrix->expectAccess($method, $uri, $guestStatus);

        if ($guestStatus === 302) {
            $response->assertRedirect(route('login'));
        }
    }

    $matrix->expectAccess('GET', route('api.v1.categories.index'), 401);
});
```

- [ ] **Step 2: Run it and expect PASS.** The routes exist and are already guarded, so the test is green on its first run. It locks the matrix in.
  `./vendor/bin/pest tests/Feature/Authorization/AdminAuthorizationTest.php`
  Expected: PASS (5 tests). If any route returns 200 for an agent or customer, the route is missing its `->can()` call. Fix the route; do not relax the test.

- [ ] **Step 3: Prove the test can fail.**
  1. Temporarily remove `->can('viewAny', Category::class)` from the web `categories.index` route.
  2. Run `./vendor/bin/pest tests/Feature/Authorization/AdminAuthorizationTest.php --filter="forbids non-admins on every admin route"`.
     Expected: FAIL with `Expected response status code [403] but received 200.`
  3. Restore the line and re-run. Expected: PASS.

- [ ] **Step 4: Format and analyse.**
  `./vendor/bin/pint --dirty && ./vendor/bin/phpstan analyse --memory-limit=1G`
  Expected: `[OK] No errors`.

- [ ] **Step 5: Commit.**

```bash
git add tests/Feature/Authorization/AdminAuthorizationTest.php
git commit -m "test(auth): cover admin routes in authorization matrix" --trailer "Refs: T13.6"
```

---

## 6. Acceptance criteria

- [ ] **Categories.**
  - Web index, create, edit, store, update and destroy work, and the API offers index, store, update and destroy.
  - The slug is lowercase kebab-case and unique, and an update may keep its own slug.
  - Destroy deletes an unreferenced category. A referenced one is deactivated instead: the web shows a flash message, and the API returns 200 with `is_active=false`. An API delete returns 204.
- [ ] **Public category list.** `api.v1.categories.index` returns active categories only, to any role that can create tickets (`TicketPolicy::create`), and 401 to guests.
- [ ] **Holidays.**
  - CRUD on web and API, with unique `Y-m-d` dates and the index filtered by year (default: the current business year).
  - Every create, update or delete flushes `HolidayRepository` after commit.
  - A holiday added through the admin route moves a new PRD-example deadline from Sunday 10:00 to Monday 10:00.
- [ ] **Users.**
  - List with prefix search (LIKE wildcards escaped) and a role filter; create; edit. There is no delete.
  - Roles change only via `ChangeUserRole` (`syncRoles`), which detaches categories from non-agents.
  - Agent categories and availability change via `SyncAgentProfile`.
- [ ] **Self-demotion is impossible.**
  - Web gets a field error on `role`, and the API gets a 422 on `role`.
  - Direct Action calls throw `CannotChangeOwnRole` (422 `cannot_change_own_role`) and roll back.
- [ ] **Resources.** `HolidayResource` and `AdminUserResource` expose explicit fields only: no password and no token.
- [ ] **Authorization.** Every admin route carries `->can(...)` and is admin-only. Agents and customers get 403, and guests get a login redirect or 401. AdminAuthorizationTest checks this through T04's `AuthorizationMatrix`.
- [ ] **Navigation.** `navigation/admin.ts` shows each item only when its `auth.can.manage*` flag is true.

## 7. Verification

```bash
./vendor/bin/pest tests/Feature/Admin tests/Feature/Authorization/AdminAuthorizationTest.php
./vendor/bin/pint --test
./vendor/bin/phpstan analyse --memory-limit=1G
./vendor/bin/pest --parallel
npm run build && npx vue-tsc --noEmit
```

Expected:
- All admin and authorization tests pass. That is 14 action tests, 13 category, 9 holiday, 11 user, and 5 authorization.
- `pint --test` passes, and PHPStan prints `[OK] No errors`.
- The full suite has no failures.
- The build succeeds with no type errors.

Manual check, as `admin@example.com`:
1. Add a holiday on the next Sunday.
2. Create a Critical ticket on Thursday at 17:00 business time (use `travelTo` in tinker, or wait). It is due Monday 10:00.
3. Open your own user. The role selector is disabled.

## Contract notes for controller

1. **New domain exception.** `CannotChangeOwnRole` (422, `cannot_change_own_role`) isn't in §3.2. Please add it to §3.2 and to TD-110's list, so T14's error table includes it.
2. **Public category list authorization.** `categories.index` is open to "any authenticated user", but §3.11 makes `CategoryPolicy::viewAny` an admin capability. The route is therefore authorized with `TicketPolicy::create`: whoever may create a ticket needs the list. Confirm, or add a `CategoryPolicy::viewActive` ability.
3. **No user delete.** §3.10 says `admin.users.*` is a "resource, without show", which would include `destroy`. This plan omits `destroy` for users because TD-025 says users are deactivated, never deleted. Categories and holidays do have `destroy`.
4. **Task-internal names not in §3.**
   - Actions: `CreateCategory`, `UpdateCategory`, `DeleteCategory`, `CreateHoliday`, `UpdateHoliday`, `DeleteHoliday`, `CreateUser`, `UpdateUser`
   - `AdminDirectory`
   - `AdminCategoryResource`, needed because the admin list shows `tickets_count` and T01's `CategoryResource` is not T13's to change.
5. **Resolved:** T04's admin policies take the model on `update`/`delete` (`update(User, Category)` and so on) and only the user on `viewAny`/`create`. The routes pass `'category'`, `'holiday'` and `'user'` for the former and the class for the latter.
6. **Existing SLA deadlines.** Holiday changes don't recalculate deadlines already stored on tickets (TD-045). The UI says so.
7. **Unpinned frontend contracts.** As in T11: the kit ships Wayfinder, but README §3 doesn't pin a client route helper for feature pages, so paths are literal; and `Paginated<T>` isn't pinned, so T13 defines a local `ResourcePage<T>` in `types/admin.ts`.
8. **spatie/laravel-permission v8.** `ChangeUserRole` keeps `syncRoles([$role->value])`. In v8, `HasRoles::syncRoles()` returns `static` and also accepts a `BackedEnum`, so passing `$role` directly would work too; the string form matches the rest of the plans. Assigning roles doesn't need `PermissionRegistrar::forgetCachedPermissions()`: the registrar caches role and permission definitions, not who holds them, and `assignRole()` only resets it when called on a `Permission`. T13 listens to no spatie events, so the v7 renames (`RoleAttachedEvent`, `RoleDetachedEvent`) don't affect it.
