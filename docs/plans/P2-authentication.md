# P2 — Authentication and access

> **Self-driving plan.** The session that runs this file is the controller. Read `docs/plans/ROADMAP.md` and `CLAUDE.md` first, then use the `subagent-driven-development` skill: run the **Waves** table below in order, dispatching every subtask of a wave as its own subagent in one message, with the model the table names, and wait for the whole wave before starting the next. Session steps (S0, S1) are done by the controller itself. Then gate once → parallel review → fix → report (ROADMAP steps 4–8). **Don't commit until the owner approves.**

**Goal:** turn the existing login, registration and token code into one complete access system, adding password reset, user and role management, a read-only permission matrix, and a route-protection audit.
**Depends on:** P1. It needs the `password_reset_tokens` table, and the agent test databases `techzu_testing_agent1…8`.
**Outcome:**
- Guests can log in, register as customers, and reset a forgotten password. The reset link goes through the `log` mailer and lands in `storage/logs/laravel.log`.
- Admins can list users (paginated, filtered by role, with prefix search), create them, and edit them, including their role and agent profile, on the web and through `/api/v1/admin/users`. They can't change their own role.
- Admins can see a read-only page, and an API list, showing each fixed role and the permissions it was seeded with.
- One audit test proves two things for every application route: guests are turned away, and a user with no role is denied by a Policy.

## Schema needs for P1
- `password_reset_tokens` (`email` primary, `token`, `created_at` nullable), the framework default. It is already in P1's scope.
- An index on `users(name)`. It serves the admin user list, which sorts by name and runs a prefix search on name. Record it in TD-021.

## Global constraints
- Use Laravel's built-in Password broker through `Illuminate\Contracts\Auth\PasswordBroker`. No Fortify, and no custom token table.
- The reset routes are named `password.request`, `password.email`, `password.reset` and `password.update`, so the default `ResetPassword` notification builds the right link.
- The forgot-password response doesn't depend on whether the email exists: same redirect, same flash text, no error (no account enumeration).
- Roles are fixed: `Role::Admin`, `Role::Agent`, `Role::Customer`. `App\Actions\Admin\ChangeUserRole` is the only code that calls `syncRoles`. Permissions are never edited at runtime.
- A user's Sanctum tokens are revoked whenever their role actually changes or their password is changed (by reset or by an admin). Token abilities mirror permissions at issue time (TD-003), so they must not outlive the role or the credentials.
- Users are never deleted (TD-025), so there is no destroy route.
- Admin routes authorize with `->can(...)` route middleware against `UserPolicy`. The roles page reuses `UserPolicy::viewAny` (`users.manage`).
- Use `Spatie\Permission\Models\Role as RoleModel` and `Spatie\Permission\Models\Permission as PermissionModel`. The bare names `Role` and `Permission` always mean the `App\Enums` classes.
- `config/inertia.php` has `testing.ensure_pages_exist = true`, and P2.2 runs before P2.3 creates the pages. P2.2 tests therefore assert components with `->component('admin/…', false)`.
- Frontend implementers format their own files with `npx vp fmt <files>`, but don't run `npm run build`, `npm run check` or `vue-tsc`.

## Waves
| Wave | Subtask | Model | Runs in parallel with |
|---|---|---|---|
| W1 | P2.1 Password reset (backend, 2 pages, Login link, tests) | sonnet | P2.2 |
| W1 | P2.2 User and role management backend | sonnet | P2.1 |
| W2 | P2.3 Users and roles pages, admin navigation | sonnet | P2.4 |
| W2 | P2.4 Authorization-matrix rows and route-protection audit | sonnet | P2.3 |

---

## P2.1 — Password reset

**Files:**
- Create:
  - `app/Actions/Auth/SendPasswordResetLink.php`
  - `app/Actions/Auth/ResetUserPassword.php`
  - `app/Http/Controllers/Auth/PasswordResetController.php`
  - `app/Http/Requests/Auth/ForgotPasswordRequest.php`
  - `app/Http/Requests/Auth/ResetPasswordRequest.php`
  - `resources/js/pages/auth/ForgotPassword.vue`
  - `resources/js/pages/auth/ResetPassword.vue`
  - `tests/Feature/Auth/PasswordResetTest.php`
- Modify:
  - `routes/web/auth.php`
  - `resources/js/pages/auth/Login.vue`
  - `tests/Feature/Auth/RegisterTest.php`

**Consumes:**
- `User`, which already uses `CanResetPassword` and `Notifiable` through `Illuminate\Foundation\Auth\User`.
- The `passwords.users` broker in `config/auth.php`: 60-minute expiry and a 60-second per-email throttle. It stays unchanged.
- The `log` mailer (`MAIL_MAILER=log` in `.env.example`; `array` in `phpunit.xml`).

**Produces:**
- Routes, all inside the existing `guest` group:

  | Method and URI | Name | Extra middleware | Controller method |
  |---|---|---|---|
  | `GET forgot-password` | `password.request` | — | `create` |
  | `POST forgot-password` | `password.email` | `throttle:5,1` | `store` |
  | `GET reset-password/{token}` | `password.reset` | — | `edit` |
  | `POST reset-password` | `password.update` | `throttle:5,1` | `update` |

- `Auth\SendPasswordResetLink::handle(string $email): void`
- `Auth\ResetUserPassword::handle(string $email, string $password, string $token): void`. It throws `ValidationException` on `email` when the token or the user is invalid, following the `IssueApiToken` precedent.
- Inertia pages:
  - `auth/ForgotPassword`: no props. It reads `flash.success`.
  - `auth/ResetPassword`: props `token: string`, `email: string`.
- Flash texts:
  - after a link request: `If an account exists for that email, a password reset link has been sent.`
  - after a reset, on `login`: `Your password has been reset. You can log in now.`

**Steps:**

1. **Write the failing test** `tests/Feature/Auth/PasswordResetTest.php`. The tests create reset tokens directly with the broker; only the link test goes through the email.

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia;

const RESET_LINK_SENT = 'If an account exists for that email, a password reset link has been sent.';

beforeEach(function (): void {
    Notification::fake();
});

it('renders the forgot and reset password pages for guests', function (): void {
    $this->get(route('password.request'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/ForgotPassword'));

    $this->get(route('password.reset', ['token' => 'abc', 'email' => 'jane@example.com']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/ResetPassword')
            ->where('token', 'abc')
            ->where('email', 'jane@example.com'));
});

it('emails a reset link that points at the reset page', function (): void {
    $user = User::factory()->create();

    $this->from(route('password.request'))
        ->post(route('password.email'), ['email' => $user->email])
        ->assertRedirect(route('password.request'))
        ->assertSessionHas('success', RESET_LINK_SENT);

    Notification::assertSentTo($user, ResetPassword::class, fn (ResetPassword $notification): bool => $notification->toMail($user)->actionUrl
        === route('password.reset', ['token' => $notification->token, 'email' => $user->email]));
});

it('answers an unknown email exactly like a known one and sends nothing', function (): void {
    $this->from(route('password.request'))
        ->post(route('password.email'), ['email' => 'nobody@example.com'])
        ->assertRedirect(route('password.request'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', RESET_LINK_SENT);

    Notification::assertNothingSent();
});

it('resets the password with a valid token and revokes every api token', function (): void {
    $user = User::factory()->create();
    $user->createToken('laptop');
    $token = Password::createToken($user);

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertRedirect(route('login'))
        ->assertSessionHas('success');

    $user->refresh();

    expect(Hash::check('new-password', $user->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0);
    $this->assertGuest();
});

it('refuses an invalid token without revealing whether the email exists', function (string $email): void {
    $user = User::factory()->create(['email' => 'jane@example.com']);

    $this->from('/reset-password/bogus')
        ->post(route('password.update'), [
            'token' => 'bogus',
            'email' => $email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertRedirect('/reset-password/bogus')
        ->assertSessionHasErrors(['email' => __('passwords.token')]);

    expect(Hash::check('password', (string) $user->fresh()?->password))->toBeTrue();
})->with([
    'known email' => ['jane@example.com'],
    'unknown email' => ['nobody@example.com'],
]);

it('throttles the password reset forms to five attempts per minute', function (string $route, array $payload): void {
    foreach (range(1, 5) as $attempt) {
        $this->post(route($route), $payload)->assertRedirect();
    }

    $this->post(route($route), $payload)->assertTooManyRequests();
})->with([
    'link request' => ['password.email', ['email' => 'nobody@example.com']],
    'reset' => ['password.update', ['token' => 'bogus', 'email' => 'nobody@example.com', 'password' => 'new-password', 'password_confirmation' => 'new-password']],
]);
```

2. **Add the role-injection test** to `tests/Feature/Auth/RegisterTest.php`, appended at the end. It proves a security boundary from TD-120: self-registration can never choose a role. Add `use App\Enums\Role;` to the imports.

```php
it('ignores any role sent with the registration form', function (): void {
    $this->post(route('register'), [
        'name' => 'Role Seeker',
        'email' => 'role.seeker@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role' => Role::Admin->value,
        'roles' => [Role::Admin->value],
    ])->assertRedirect(route('home'));

    expect(User::query()->where('email', 'role.seeker@example.com')->sole()->getRoleNames()->all())
        ->toBe([Role::Customer->value]);
});
```

3. **Run them and expect FAIL.** The new test fails with `Route [password.request] not defined`. The register test should already pass, which is expected: it locks in existing behaviour.
   `DB_DATABASE=techzu_testing_agent<N> ./vendor/bin/pest tests/Feature/Auth/PasswordResetTest.php tests/Feature/Auth/RegisterTest.php`

4. **Create** `app/Http/Requests/Auth/ForgotPasswordRequest.php` and `app/Http/Requests/Auth/ResetPasswordRequest.php`. Both are `final`, with `authorize(): bool` returning `true` and a `/** @return array<string, ValidationRule|array<mixed>|string> */ rules()`, exactly like `RegisterRequest`. Their rules:
   - `ForgotPasswordRequest`: `'email' => ['required', 'string', 'email', 'max:255']`
   - `ResetPasswordRequest`: `'token' => ['required', 'string']`, `'email' => ['required', 'string', 'email', 'max:255']`, `'password' => ['required', 'string', 'confirmed', Password::defaults()]`

5. **Create** `app/Actions/Auth/SendPasswordResetLink.php`. The broker's status is deliberately ignored, so the caller can't tell an unknown or throttled email from a sent link.

```php
<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use Illuminate\Contracts\Auth\PasswordBroker;

final class SendPasswordResetLink
{
    public function __construct(private readonly PasswordBroker $broker) {}

    public function handle(string $email): void
    {
        $this->broker->sendResetLink(['email' => $email]);
    }
}
```

6. **Create** `app/Actions/Auth/ResetUserPassword.php`. The broker checks the token and then calls back with the user. The new password, a fresh remember token and the token revocation are written in one transaction. An unknown user gets the same error as a bad token.

```php
<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ResetUserPassword
{
    public function __construct(private readonly PasswordBroker $broker) {}

    public function handle(string $email, string $password, string $token): void
    {
        $status = $this->broker->reset(
            ['email' => $email, 'password' => $password, 'token' => $token],
            function (User $user, string $password): void {
                DB::transaction(function () use ($user, $password): void {
                    $user->forceFill([
                        'password' => $password,
                        'remember_token' => Str::random(60),
                    ])->save();

                    $user->tokens()->delete();
                });

                event(new PasswordReset($user));
            },
        );

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __(PasswordBroker::INVALID_TOKEN)]);
        }
    }
}
```

7. **Create** `app/Http/Controllers/Auth/PasswordResetController.php`.

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ResetUserPassword;
use App\Actions\Auth\SendPasswordResetLink;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class PasswordResetController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/ForgotPassword');
    }

    public function store(ForgotPasswordRequest $request, SendPasswordResetLink $sendLink): RedirectResponse
    {
        $sendLink->handle($request->string('email')->toString());

        return back()->with('success', 'If an account exists for that email, a password reset link has been sent.');
    }

    public function edit(Request $request, string $token): Response
    {
        return Inertia::render('auth/ResetPassword', [
            'token' => $token,
            'email' => $request->string('email')->toString(),
        ]);
    }

    public function update(ResetPasswordRequest $request, ResetUserPassword $resetPassword): RedirectResponse
    {
        $resetPassword->handle(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request->string('token')->toString(),
        );

        return to_route('login')->with('success', 'Your password has been reset. You can log in now.');
    }
}
```

8. **Modify** `routes/web/auth.php`. Add `use App\Http\Controllers\Auth\PasswordResetController;`, and append these four lines inside the `guest` group, after the `register` POST:

```php
    Route::get('forgot-password', [PasswordResetController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [PasswordResetController::class, 'store'])->middleware('throttle:5,1')->name('password.email');
    Route::get('reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:5,1')->name('password.update');
```

9. **Run the tests and expect PASS** (8 PasswordResetTest cases including dataset rows, plus RegisterTest's 3).
    `DB_DATABASE=techzu_testing_agent<N> ./vendor/bin/pest tests/Feature/Auth/PasswordResetTest.php tests/Feature/Auth/RegisterTest.php`

10. **Create** `resources/js/pages/auth/ForgotPassword.vue`. It is the kit's page restored, with the status text read from the shared `flash.success` (the `AuthLayout` shows no flash). Wayfinder generates `@/routes/password` from the route names during the gate build.

```vue
<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { email } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Forgot password',
        description: 'Enter your email to receive a password reset link',
    },
});

const page = usePage();
const status = computed(() => page.props.flash.success);
</script>

<template>
    <Head title="Forgot password" />

    <div
        v-if="status"
        role="status"
        class="mb-4 text-center text-sm font-medium text-green-600"
    >
        {{ status }}
    </div>

    <div class="space-y-6">
        <Form v-bind="email.form()" v-slot="{ errors, processing }">
            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    required
                    autocomplete="email"
                    v-focus
                    placeholder="email@example.com"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="my-6 flex items-center justify-start">
                <Button
                    type="submit"
                    class="w-full"
                    :disabled="processing"
                    data-test="email-password-reset-link-button"
                >
                    <Spinner v-if="processing" />
                    Email password reset link
                </Button>
            </div>
        </Form>

        <div class="space-x-1 text-center text-sm text-muted-foreground">
            <span>Or, return to</span>
            <TextLink :href="login()">log in</TextLink>
        </div>
    </div>
</template>
```

11. **Create** `resources/js/pages/auth/ResetPassword.vue`. The token and the email from the link are merged into the submitted data, and the email field is read-only.

```vue
<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { update } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Reset password',
        description: 'Please enter your new password below',
    },
});

const props = defineProps<{
    token: string;
    email: string;
}>();
</script>

<template>
    <Head title="Reset password" />

    <Form
        v-bind="update.form()"
        :transform="(data) => ({ ...data, token: props.token, email: props.email })"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="email">Email</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    autocomplete="email"
                    :model-value="props.email"
                    readonly
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="password">Password</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    autocomplete="new-password"
                    v-focus
                    placeholder="Password"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Confirm password</Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    required
                    autocomplete="new-password"
                    placeholder="Confirm password"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <Button
                type="submit"
                class="mt-4 w-full"
                :disabled="processing"
                data-test="reset-password-button"
            >
                <Spinner v-if="processing" />
                Reset password
            </Button>
        </div>
    </Form>
</template>
```

12. **Modify** `resources/js/pages/auth/Login.vue`. This step makes three changes:
    - Add a "Forgot password?" link next to the password label.
    - Show the flash success, which carries the reset confirmation.
    - Renumber the sign-up link's tabindex to 6.

    Add these imports: `usePage` (beside `Form, Head`), `computed` from `vue`, and `request` from `@/routes/password`. Then add the following to the script, after `defineOptions`:

```ts
const page = usePage();
const status = computed(() => page.props.flash.success);
```

   Insert this block as the first child inside `<Form …>`, before `<div class="grid gap-6">`:

```vue
        <div
            v-if="status"
            role="status"
            class="text-center text-sm font-medium text-green-600"
        >
            {{ status }}
        </div>
```

   Replace `<Label for="password">Password</Label>` with:

```vue
                <div class="flex items-center justify-between">
                    <Label for="password">Password</Label>
                    <TextLink
                        :href="request()"
                        class="text-sm"
                        :tabindex="5"
                        >Forgot password?</TextLink
                    >
                </div>
```

   Change the sign-up link to `<TextLink :href="register()" :tabindex="6">Sign up</TextLink>`.

13. **Format:** `./vendor/bin/pint app/Actions/Auth app/Http/Controllers/Auth app/Http/Requests/Auth routes/web/auth.php tests/Feature/Auth && npx vp fmt resources/js/pages/auth`

**Proposed commit:** `feat(auth): add password reset through the built-in broker`
`Refs: P2.1` · `Implements: TD-002, TD-120`

---

## P2.2 — User and role management backend

**Files:**
- Create:
  - `app/Exceptions/Domain/CannotChangeOwnRole.php`
  - `app/Actions/Admin/ChangeUserRole.php`
  - `app/Actions/Admin/SyncAgentProfile.php`
  - `app/Actions/Admin/CreateUser.php`
  - `app/Actions/Admin/UpdateUser.php`
  - `app/Services/Users/UserDirectory.php`
  - `app/Http/Requests/Admin/IndexUsersRequest.php`
  - `app/Http/Requests/Admin/StoreUserRequest.php`
  - `app/Http/Requests/Admin/UpdateUserRequest.php`
  - `app/Http/Resources/V1/AdminUserResource.php`
  - `app/Http/Resources/V1/RoleResource.php`
  - `app/Http/Controllers/Web/Admin/UserController.php`
  - `app/Http/Controllers/Web/Admin/RoleController.php`
  - `app/Http/Controllers/Api/V1/Admin/UserController.php`
  - `app/Http/Controllers/Api/V1/Admin/RoleController.php`
  - `routes/web/users.php`
  - `routes/api/v1/users.php`
  - `tests/Feature/Admin/UserActionsTest.php`
  - `tests/Feature/Admin/UserManagementTest.php`
- Modify:
  - `app/Enums/Permission.php` (adds `label()`)

**Consumes:**
- `UserPolicy::viewAny/create/update`, all `users.manage`.
- `User` (`categories()`, `tokens()`, `HasRoles`, fillable `name, email, password, is_available`).
- `Category`.
- `Role::label()` and `Permission::forRole()`.
- The `DomainException` renderer in `bootstrap/app.php`: JSON `{message, code}` for the API, and `back()->with('error', …)` for the web.

**Produces (binding for P2.3, P2.4 and P5):**
- Actions in `App\Actions\Admin`:
  - `ChangeUserRole::handle(User $admin, User $user, Role $role): User`. Keeping the current single role is a no-op. Otherwise it throws `CannotChangeOwnRole` if `$admin` is `$user`. It then calls `syncRoles`, detaches categories unless the new role is agent, and revokes the user's tokens.
  - `SyncAgentProfile::handle(User $admin, User $agent, array $categoryIds, bool $isAvailable): User`
  - `CreateUser::handle(User $admin, array $data): User`
  - `UpdateUser::handle(User $admin, User $user, array $data): User`
  - `$data` has the shape `array{name: string, email: string, password: string|null, role: string, category_ids: list<int>|null, is_available: bool|null}`. A `null` `category_ids` or `is_available` means "leave unchanged" on update, and "none" or "available" on create.
- `CannotChangeOwnRole` returns 422 with code `cannot_change_own_role` and the default message `You cannot change your own role.`
- `Permission::label(): string`
- Resources:
  - `AdminUserResource`: `{id, name, email, role, is_available, last_assigned_at, categories?: [{id, name}], created_at}`
  - `RoleResource`: `{value, label, permissions: string[]}`
- Web routes, file `routes/web/users.php`, middleware `auth`, prefix `admin`, names prefixed `admin.`:

  | Method and URI | Name | Policy middleware | Inertia component and props |
  |---|---|---|---|
  | `GET admin/users` | `admin.users.index` | `can:viewAny,App\Models\User` | `admin/users/Index`: `users: Paginated<AdminUser>`, `filters: {q: string\|null, role: RoleValue\|null}`, `roles: RoleOption[]` |
  | `GET admin/users/create` | `admin.users.create` | `can:create,App\Models\User` | `admin/users/Create`: `roles`, `categories: CategoryOption[]` |
  | `POST admin/users` | `admin.users.store` | `can:create,App\Models\User` | redirects to `admin.users.index`, flash `User created.` |
  | `GET admin/users/{user}/edit` | `admin.users.edit` | `can:update,user` | `admin/users/Edit`: `user: AdminUser`, `roles`, `categories`, `isSelf: boolean` |
  | `PATCH admin/users/{user}` | `admin.users.update` | `can:update,user` | redirects to `admin.users.index`, flash `User updated.` |
  | `GET admin/roles` | `admin.roles.index` | `can:viewAny,App\Models\User` | `admin/roles/Index`: `roles: RolePermissions[]`, `permissions: PermissionOption[]` |

- API routes, file `routes/api/v1/users.php`, middleware `auth:sanctum`, prefix `admin`, names prefixed `api.v1.admin.`:

  | Method and URI | Name | Policy middleware | Response |
  |---|---|---|---|
  | `GET api/v1/admin/users` | `api.v1.admin.users.index` | `can:viewAny,App\Models\User` | `AdminUserResource` page |
  | `POST api/v1/admin/users` | `api.v1.admin.users.store` | `can:create,App\Models\User` | 201 `AdminUserResource` |
  | `PATCH api/v1/admin/users/{user}` | `api.v1.admin.users.update` | `can:update,user` | 200 `AdminUserResource` |
  | `GET api/v1/admin/roles` | `api.v1.admin.roles.index` | `can:viewAny,App\Models\User` | `RoleResource` collection |

- Query parameters for `admin.users.index` on both surfaces: `q` (prefix of name or email, LIKE wildcards escaped), `role` (a `Role` value), `page`, and `per_page` (1–100, default 20).

**Steps:**

1. **Write the failing Action test** `tests/Feature/Admin/UserActionsTest.php`.

```php
<?php

declare(strict_types=1);

use App\Actions\Admin\ChangeUserRole;
use App\Actions\Admin\CreateUser;
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

it('leaves a user with exactly the new role and revokes their api tokens', function (): void {
    $user = User::factory()->customer()->create();
    $user->createToken('cli');

    app(ChangeUserRole::class)->handle($this->admin, $user, Role::Agent);

    $user->refresh();
    expect($user->getRoleNames()->all())->toBe([Role::Agent->value])
        ->and($user->tokens()->count())->toBe(0);
});

it('treats keeping the same role as a no-op, even for the admin themselves', function (): void {
    $this->admin->createToken('cli');

    app(ChangeUserRole::class)->handle($this->admin, $this->admin, Role::Admin);

    expect($this->admin->fresh()?->getRoleNames()->all())->toBe([Role::Admin->value])
        ->and($this->admin->tokens()->count())->toBe(1);
});

it('detaches agent categories when a user stops being an agent', function (): void {
    $agent = User::factory()->agent([$this->billing->id])->create();

    app(ChangeUserRole::class)->handle($this->admin, $agent, Role::Customer);

    expect($agent->categories()->count())->toBe(0);
});

it('refuses to let an admin change their own role', function (Role $role): void {
    expect(fn () => app(ChangeUserRole::class)->handle($this->admin, $this->admin, $role))
        ->toThrow(CannotChangeOwnRole::class, 'You cannot change your own role.');

    expect($this->admin->fresh()?->getRoleNames()->all())->toBe([Role::Admin->value]);
})->with([Role::Agent, Role::Customer]);

it('creates an agent with a role, categories and availability', function (): void {
    $user = app(CreateUser::class)->handle($this->admin, [
        'name' => 'New Agent',
        'email' => 'new.agent@example.com',
        'password' => 'S3cure-passw0rd!',
        'role' => Role::Agent->value,
        'category_ids' => [$this->billing->id, $this->hardware->id],
        'is_available' => false,
    ])->fresh();

    expect($user?->getRoleNames()->all())->toBe([Role::Agent->value])
        ->and($user?->categories()->count())->toBe(2)
        ->and($user?->is_available)->toBeFalse()
        ->and(Hash::check('S3cure-passw0rd!', (string) $user?->password))->toBeTrue();
});

it('updates a user, keeping the password when none is given and syncing the agent profile', function (): void {
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
        ->and($user->getRoleNames()->all())->toBe([Role::Agent->value])
        ->and($user->is_available)->toBeFalse()
        ->and($user->categories()->pluck('categories.id')->all())->toBe([$this->billing->id]);
});

it('revokes api tokens when an admin sets a new password', function (): void {
    $user = User::factory()->customer()->create();
    $user->createToken('cli');

    app(UpdateUser::class)->handle($this->admin, $user, [
        'name' => $user->name,
        'email' => $user->email,
        'password' => 'brand-new-password',
        'role' => Role::Customer->value,
        'category_ids' => null,
        'is_available' => null,
    ]);

    expect(Hash::check('brand-new-password', (string) $user->fresh()?->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0);
});

it('rolls back the whole update when the admin tries to demote themselves', function (): void {
    expect(fn () => app(UpdateUser::class)->handle($this->admin, $this->admin, [
        'name' => 'Should not stick',
        'email' => $this->admin->email,
        'password' => null,
        'role' => Role::Customer->value,
        'category_ids' => null,
        'is_available' => null,
    ]))->toThrow(CannotChangeOwnRole::class);

    expect($this->admin->fresh()?->name)->not->toBe('Should not stick')
        ->and($this->admin->fresh()?->getRoleNames()->all())->toBe([Role::Admin->value]);
});
```

2. **Write the failing HTTP test** `tests/Feature/Admin/UserManagementTest.php`. It covers the HTTP mapping only: props, resource shapes, status codes, filters and validation. The rules themselves are tested on the Actions.

```php
<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Category;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create(['name' => 'Ada Admin', 'email' => 'ada@example.com']);
    $this->category = Category::factory()->create(['name' => 'Billing']);
});

it('lists users with role and agent profile, filtered by prefix search and role', function (): void {
    User::factory()->agent([$this->category->id])->create(['name' => 'Bob Agent', 'email' => 'bob@example.com']);
    User::factory()->customer()->create(['name' => 'Cara Customer', 'email' => 'cara@example.com']);

    $this->actingAs($this->admin)->get(route('admin.users.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/users/Index', false)
            ->has('users.data', 3)
            ->where('users.data.1.name', 'Bob Agent')
            ->where('users.data.1.role', Role::Agent->value)
            ->where('users.data.1.categories.0.name', 'Billing')
            ->missing('users.data.1.password')
            ->has('roles', 3));

    $this->actingAs($this->admin)->get(route('admin.users.index', ['q' => 'car']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('users.data', 1)->where('filters.q', 'car'));

    $this->actingAs($this->admin)->get(route('admin.users.index', ['role' => Role::Agent->value]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('users.data', 1)
            ->where('users.data.0.email', 'bob@example.com')
            ->where('filters.role', Role::Agent->value));

    $this->actingAs($this->admin)->get(route('admin.users.index', ['q' => '%']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('users.data', 0));
});

it('renders the create and edit pages, flagging self-edit', function (): void {
    $this->actingAs($this->admin)->get(route('admin.users.create'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/users/Create', false)
            ->has('roles', 3)
            ->has('categories', 1));

    $this->actingAs($this->admin)->get(route('admin.users.edit', $this->admin))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/users/Edit', false)
            ->where('user.id', $this->admin->id)
            ->where('user.role', Role::Admin->value)
            ->where('isSelf', true));
});

it('creates a user from the web form and returns to the list', function (): void {
    $this->actingAs($this->admin)
        ->post(route('admin.users.store'), [
            'name' => 'New Agent',
            'email' => 'new.agent@example.com',
            'password' => 'S3cure-passw0rd!',
            'role' => Role::Agent->value,
            'category_ids' => [$this->category->id],
            'is_available' => false,
        ])
        ->assertRedirect(route('admin.users.index'))
        ->assertSessionHas('success', 'User created.');

    expect(User::query()->where('email', 'new.agent@example.com')->sole()->getRoleNames()->all())
        ->toBe([Role::Agent->value]);
});

it('rejects users that would break the account rules', function (array $payload, string $field): void {
    User::factory()->customer()->create(['email' => 'taken@example.com']);

    $this->actingAs($this->admin)
        ->post(route('admin.users.store'), array_merge([
            'name' => 'Someone',
            'email' => 'someone@example.com',
            'password' => 'S3cure-passw0rd!',
            'role' => Role::Customer->value,
        ], $payload))
        ->assertSessionHasErrors($field);
})->with([
    'duplicate email' => [['email' => 'taken@example.com'], 'email'],
    'unknown role' => [['role' => 'superuser'], 'role'],
    'unknown category' => [['role' => 'agent', 'category_ids' => [999999]], 'category_ids.0'],
]);

it('maps a refused self-demotion to a flash error on the web and a 422 code on the API', function (): void {
    $this->actingAs($this->admin)
        ->from(route('admin.users.edit', $this->admin))
        ->patch(route('admin.users.update', $this->admin), [
            'name' => 'Ada Admin',
            'email' => 'ada@example.com',
            'role' => Role::Agent->value,
        ])
        ->assertRedirect(route('admin.users.edit', $this->admin))
        ->assertSessionHas('error', 'You cannot change your own role.');

    Sanctum::actingAs($this->admin, ['*']);
    $this->patchJson(route('api.v1.admin.users.update', $this->admin), [
        'name' => 'Ada Admin',
        'email' => 'ada@example.com',
        'role' => Role::Customer->value,
    ])->assertUnprocessable()
        ->assertJsonPath('code', 'cannot_change_own_role');

    expect($this->admin->fresh()?->getRoleNames()->all())->toBe([Role::Admin->value]);
});

it('manages users over the API', function (): void {
    Sanctum::actingAs($this->admin, ['*']);

    $id = $this->postJson(route('api.v1.admin.users.store'), [
        'name' => 'Api Agent',
        'email' => 'api.agent@example.com',
        'password' => 'S3cure-passw0rd!',
        'role' => Role::Agent->value,
        'category_ids' => [$this->category->id],
    ])->assertCreated()
        ->assertJsonPath('data.role', Role::Agent->value)
        ->assertJsonPath('data.is_available', true)
        ->assertJsonPath('data.categories.0.id', $this->category->id)
        ->assertJsonMissingPath('data.password')
        ->json('data.id');

    $this->patchJson(route('api.v1.admin.users.update', $id), [
        'name' => 'Api Agent',
        'email' => 'api.agent@example.com',
        'role' => Role::Agent->value,
        'category_ids' => [],
        'is_available' => false,
    ])->assertOk()
        ->assertJsonPath('data.is_available', false)
        ->assertJsonPath('data.categories', []);

    $this->getJson(route('api.v1.admin.users.index', ['role' => Role::Agent->value]))
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.email', 'api.agent@example.com');
});

it('shows each fixed role with exactly its seeded permissions', function (): void {
    $permissionsOf = static fn (Role $role): array => array_map(
        static fn (Permission $permission): string => $permission->value,
        Permission::forRole($role),
    );

    $this->actingAs($this->admin)->get(route('admin.roles.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/roles/Index', false)
            ->where('roles.0.value', Role::Admin->value)
            ->where('roles.2.value', Role::Customer->value)
            ->where('roles.2.permissions', fn ($permissions): bool => collect($permissions)->sort()->values()->all()
                === collect($permissionsOf(Role::Customer))->sort()->values()->all())
            ->has('permissions', count(Permission::cases()))
            ->where('permissions.0.value', Permission::TicketsCreate->value));

    Sanctum::actingAs($this->admin, ['*']);
    $response = $this->getJson(route('api.v1.admin.roles.index'))->assertOk();

    expect($response->json('data.*.value'))->toBe([Role::Admin->value, Role::Agent->value, Role::Customer->value])
        ->and($response->json('data.1.permissions'))->toEqualCanonicalizing($permissionsOf(Role::Agent));
});
```

3. **Run both and expect FAIL** with `Class "App\Actions\Admin\ChangeUserRole" not found` and `Route [admin.users.index] not defined`.
   `DB_DATABASE=techzu_testing_agent<N> ./vendor/bin/pest tests/Feature/Admin/UserActionsTest.php tests/Feature/Admin/UserManagementTest.php`

4. **Create** `app/Exceptions/Domain/CannotChangeOwnRole.php`. Copy `AgentNotEligible`: a `final` class with a constructor whose default message is `'You cannot change your own role.'`, `status()` returning `422`, and `errorCode()` returning `'cannot_change_own_role'`.

5. **Create** `app/Actions/Admin/ChangeUserRole.php`. It is the only place `syncRoles` is called (TD-010, TD-120). An admin can never demote themselves, so the system always keeps at least one admin. Categories only mean something for agents (TD-050).

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
    public function handle(User $admin, User $user, Role $role): User
    {
        if ($user->getRoleNames()->all() === [$role->value]) {
            return $user;
        }

        if ($admin->is($user)) {
            throw new CannotChangeOwnRole;
        }

        return DB::transaction(function () use ($user, $role): User {
            $user->syncRoles([$role->value]);

            if ($role !== Role::Agent) {
                $user->categories()->detach();
            }

            $user->tokens()->delete();

            return $user;
        });
    }
}
```

6. **Create** `app/Actions/Admin/SyncAgentProfile.php`.

```php
<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class SyncAgentProfile
{
    /** @param list<int> $categoryIds */
    public function handle(User $admin, User $agent, array $categoryIds, bool $isAvailable): User
    {
        return DB::transaction(function () use ($agent, $categoryIds, $isAvailable): User {
            $agent->categories()->sync(array_values(array_unique($categoryIds)));
            $agent->is_available = $isAvailable;
            $agent->save();

            return $agent;
        });
    }
}
```

7. **Create** `app/Actions/Admin/CreateUser.php`.

```php
<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CreateUser
{
    public function __construct(
        private readonly ChangeUserRole $changeRole,
        private readonly SyncAgentProfile $syncAgent,
    ) {}

    /** @param array{name: string, email: string, password: string|null, role: string, category_ids: list<int>|null, is_available: bool|null} $data */
    public function handle(User $admin, array $data): User
    {
        return DB::transaction(function () use ($admin, $data): User {
            $isAvailable = $data['is_available'] ?? true;

            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => (string) $data['password'],
                'is_available' => $isAvailable,
            ]);

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

8. **Create** `app/Actions/Admin/UpdateUser.php`. The role changes first, so a refused self-demotion aborts before any field changes. A new password revokes the user's tokens.

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

    /** @param array{name: string, email: string, password: string|null, role: string, category_ids: list<int>|null, is_available: bool|null} $data */
    public function handle(User $admin, User $user, array $data): User
    {
        return DB::transaction(function () use ($admin, $user, $data): User {
            $role = Role::from($data['role']);

            $this->changeRole->handle($admin, $user, $role);

            $user->fill(['name' => $data['name'], 'email' => $data['email']]);

            if ($data['password'] !== null && $data['password'] !== '') {
                $user->password = $data['password'];
                $user->tokens()->delete();
            }

            $user->save();

            if ($role === Role::Agent) {
                $this->syncAgent->handle(
                    $admin,
                    $user,
                    $data['category_ids'] ?? $this->currentCategoryIds($user),
                    $data['is_available'] ?? $user->is_available,
                );
            }

            return $user;
        });
    }

    /** @return list<int> */
    private function currentCategoryIds(User $user): array
    {
        return $user->categories()
            ->pluck('categories.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }
}
```

9. **Modify** `app/Enums/Permission.php`. Add this method after `forRole()`. The roles page shows these labels.

```php
    public function label(): string
    {
        return match ($this) {
            self::TicketsCreate => 'Create tickets',
            self::TicketsCreateOnBehalf => 'Create tickets on behalf of customers',
            self::TicketsViewAny => 'View all tickets',
            self::TicketsViewOwn => 'View own tickets',
            self::TicketsReply => 'Reply to tickets',
            self::TicketsNotesInternal => 'Read and write internal notes',
            self::TicketsClaim => 'Claim unassigned tickets',
            self::TicketsUpdateAny => 'Update any ticket',
            self::TicketsUpdateAssigned => 'Update tickets assigned to them',
            self::TicketsUpdateOwn => 'Update their own tickets',
            self::TicketsAssignAny => 'Assign tickets to any agent',
            self::TicketsHandoffOwn => 'Hand off their own tickets',
            self::DashboardView => 'View the dashboard',
            self::ImportsManage => 'Import tickets from CSV',
            self::UsersManage => 'Manage users and roles',
            self::CategoriesManage => 'Manage categories',
            self::HolidaysManage => 'Manage holidays',
        };
    }
```

10. **Create** `app/Services/Users/UserDirectory.php`. It is the read model behind both user controllers. Its queries eager-load with column lists, the list sorts by name (served by the new `users(name)` index), and the role filter uses spatie's `role` scope, which reads the indexed `model_has_roles`. The permission matrix is read from the seeded tables and filtered to the fixed enums.

```php
<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Category;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;

final class UserDirectory
{
    private const LIST_COLUMNS = [
        'users.id', 'users.name', 'users.email', 'users.is_available', 'users.last_assigned_at', 'users.created_at',
    ];

    private const RELATIONS = ['roles:id,name', 'categories:id,name'];

    /**
     * @param  array{q: string|null, role: string|null, per_page: int}  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $prefix = $filters['q'] === null ? null : addcslashes($filters['q'], '%_\\').'%';
        $role = $filters['role'];

        return User::query()
            ->select(self::LIST_COLUMNS)
            ->with(self::RELATIONS)
            ->when($prefix !== null, static function (Builder $query) use ($prefix): void {
                $query->where(static function (Builder $match) use ($prefix): void {
                    $match->where('users.name', 'like', $prefix)->orWhere('users.email', 'like', $prefix);
                });
            })
            ->when($role !== null, static function (Builder $query) use ($role): void {
                $query->role($role);
            })
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->paginate($filters['per_page'])
            ->withQueryString();
    }

    public function load(User $user): User
    {
        return $user->load(self::RELATIONS);
    }

    /** @return list<array{value: string, label: string}> */
    public function roleOptions(): array
    {
        return array_map(
            static fn (Role $role): array => ['value' => $role->value, 'label' => $role->label()],
            Role::cases(),
        );
    }

    /** @return list<array{id: int, name: string, is_active: bool}> */
    public function categoryOptions(): array
    {
        return Category::query()
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name', 'is_active'])
            ->map(static fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'is_active' => (bool) $category->is_active,
            ])
            ->values()
            ->all();
    }

    /** @return Collection<int, RoleModel> */
    public function rolesWithPermissions(): Collection
    {
        $order = array_flip(array_map(static fn (Role $role): string => $role->value, Role::cases()));

        return RoleModel::query()
            ->whereIn('name', array_keys($order))
            ->with('permissions:id,name')
            ->get(['id', 'name'])
            ->sortBy(static fn (RoleModel $role): int => $order[$role->name])
            ->values();
    }

    /** @return list<array{value: string, label: string}> */
    public function permissionOptions(): array
    {
        $seeded = PermissionModel::query()->pluck('name')->all();

        return array_values(array_map(
            static fn (Permission $permission): array => ['value' => $permission->value, 'label' => $permission->label()],
            array_filter(
                Permission::cases(),
                static fn (Permission $permission): bool => in_array($permission->value, $seeded, true),
            ),
        ));
    }
}
```

11. **Create** `app/Http/Requests/Admin/IndexUsersRequest.php`.

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

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::enum(Role::class)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** @return array{q: string|null, role: string|null, per_page: int} */
    public function filters(): array
    {
        return [
            'q' => $this->filled('q') ? $this->string('q')->toString() : null,
            'role' => $this->filled('role') ? $this->string('role')->toString() : null,
            'per_page' => $this->integer('per_page', 20),
        ];
    }
}
```

12. **Create** `app/Http/Requests/Admin/StoreUserRequest.php`. Authorization lives on the route (`can:` middleware), so `authorize()` returns true, as `LoginRequest` does. `userData()` gives the Actions their typed shape.

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

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::defaults()],
            'role' => ['required', Rule::enum(Role::class)],
            'category_ids' => ['sometimes', 'array'],
            'category_ids.*' => ['integer', 'distinct', Rule::exists('categories', 'id')],
            'is_available' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array{name: string, email: string, password: string|null, role: string, category_ids: list<int>|null, is_available: bool|null} */
    public function userData(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'email' => $this->string('email')->toString(),
            'password' => $this->filled('password') ? $this->string('password')->toString() : null,
            'role' => $this->string('role')->toString(),
            'category_ids' => $this->has('category_ids')
                ? array_values(array_map('intval', (array) $this->validated('category_ids', [])))
                : null,
            'is_available' => $this->has('is_available') ? $this->boolean('is_available') : null,
        ];
    }
}
```

13. **Create** `app/Http/Requests/Admin/UpdateUserRequest.php`.

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class UpdateUserRequest extends StoreUserRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('user'))],
            'password' => ['nullable', 'string', Password::defaults()],
        ]);
    }
}
```

14. **Create** `app/Http/Resources/V1/AdminUserResource.php`. It uses explicit fields: no password, no remember token, no pivots (TD-120).

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Category;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
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

15. **Create** `app/Http/Resources/V1/RoleResource.php`.

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Enums\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;

/** @mixin RoleModel */
final class RoleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $role = Role::from($this->name);

        return [
            'value' => $role->value,
            'label' => $role->label(),
            'permissions' => $this->permissions
                ->map(static fn (PermissionModel $permission): string => $permission->name)
                ->values()
                ->all(),
        ];
    }
}
```

16. **Create** `app/Http/Controllers/Web/Admin/UserController.php`.

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
use App\Models\User;
use App\Services\Users\UserDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class UserController extends Controller
{
    public function __construct(private readonly UserDirectory $directory) {}

    public function index(IndexUsersRequest $request): Response
    {
        $filters = $request->filters();

        return Inertia::render('admin/users/Index', [
            'users' => AdminUserResource::collection($this->directory->paginate($filters)),
            'filters' => ['q' => $filters['q'], 'role' => $filters['role']],
            'roles' => $this->directory->roleOptions(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/users/Create', [
            'roles' => $this->directory->roleOptions(),
            'categories' => $this->directory->categoryOptions(),
        ]);
    }

    public function store(StoreUserRequest $request, CreateUser $createUser): RedirectResponse
    {
        $createUser->handle($this->admin($request), $request->userData());

        return to_route('admin.users.index')->with('success', 'User created.');
    }

    public function edit(Request $request, User $user): Response
    {
        return Inertia::render('admin/users/Edit', [
            'user' => AdminUserResource::make($this->directory->load($user))->resolve($request),
            'roles' => $this->directory->roleOptions(),
            'categories' => $this->directory->categoryOptions(),
            'isSelf' => $this->admin($request)->is($user),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user, UpdateUser $updateUser): RedirectResponse
    {
        $updateUser->handle($this->admin($request), $user, $request->userData());

        return to_route('admin.users.index')->with('success', 'User updated.');
    }

    private function admin(Request $request): User
    {
        /** @var User $admin */
        $admin = $request->user();

        return $admin;
    }
}
```

17. **Create** `app/Http/Controllers/Web/Admin/RoleController.php`, a `final` invokable controller:
    - `__invoke(Request $request, UserDirectory $directory): Inertia\Response`
    - it renders `'admin/roles/Index'` with `'roles' => RoleResource::collection($directory->rolesWithPermissions())->resolve($request)` and `'permissions' => $directory->permissionOptions()`

18. **Create** `app/Http/Controllers/Api/V1/Admin/UserController.php`. It is `final`, and its constructor and private `admin(Request): User` helper are the same as the web controller's. Its methods:
    - `index(IndexUsersRequest $request): AnonymousResourceCollection` returns `AdminUserResource::collection($this->directory->paginate($request->filters()))`.
    - `store(StoreUserRequest $request, CreateUser $createUser): JsonResponse` returns `AdminUserResource::make($this->directory->load($user))->response()->setStatusCode(201)`, where `$user` comes from `$createUser->handle($this->admin($request), $request->userData())`.
    - `update(UpdateUserRequest $request, User $user, UpdateUser $updateUser): AdminUserResource` returns `AdminUserResource::make($this->directory->load($updateUser->handle($this->admin($request), $user, $request->userData())))`.

19. **Create** `app/Http/Controllers/Api/V1/Admin/RoleController.php`, a `final` invokable controller:
    - `__invoke(UserDirectory $directory): AnonymousResourceCollection`
    - it returns `RoleResource::collection($directory->rolesWithPermissions())`

20. **Create** `routes/web/users.php`.

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\Web\Admin\RoleController;
use App\Http\Controllers\Web\Admin\UserController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('users', [UserController::class, 'index'])->name('users.index')->can('viewAny', User::class);
    Route::get('users/create', [UserController::class, 'create'])->name('users.create')->can('create', User::class);
    Route::post('users', [UserController::class, 'store'])->name('users.store')->can('create', User::class);
    Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit')->can('update', 'user');
    Route::patch('users/{user}', [UserController::class, 'update'])->name('users.update')->can('update', 'user');
    Route::get('roles', RoleController::class)->name('roles.index')->can('viewAny', User::class);
});
```

21. **Create** `routes/api/v1/users.php`.

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('users', [UserController::class, 'index'])->name('users.index')->can('viewAny', User::class);
    Route::post('users', [UserController::class, 'store'])->name('users.store')->can('create', User::class);
    Route::patch('users/{user}', [UserController::class, 'update'])->name('users.update')->can('update', 'user');
    Route::get('roles', RoleController::class)->name('roles.index')->can('viewAny', User::class);
});
```

22. **Run both and expect PASS** (18 tests including dataset rows: 9 in each file). A `LazyLoadingViolationException` means a relation is missing from `UserDirectory::RELATIONS`.
    `DB_DATABASE=techzu_testing_agent<N> ./vendor/bin/pest tests/Feature/Admin/UserActionsTest.php tests/Feature/Admin/UserManagementTest.php`

23. **Format:** `./vendor/bin/pint app/Actions/Admin app/Exceptions/Domain/CannotChangeOwnRole.php app/Enums/Permission.php app/Services/Users app/Http/Requests/Admin app/Http/Resources/V1/AdminUserResource.php app/Http/Resources/V1/RoleResource.php app/Http/Controllers/Web/Admin app/Http/Controllers/Api/V1/Admin routes/web/users.php routes/api/v1/users.php tests/Feature/Admin`

**Proposed commit:** `feat(admin): add user and role management with a read-only permission matrix`
`Refs: P2.2` · `Implements: TD-010, TD-011, TD-025, TD-050, TD-110, TD-120`

---

## P2.3 — Users and roles pages, admin navigation

**Files:**
- Create:
  - `resources/js/types/users.ts`
  - `resources/js/config/navigation/admin.ts`
  - `resources/js/components/admin/UserForm.vue`
  - `resources/js/pages/admin/users/Index.vue`
  - `resources/js/pages/admin/users/Create.vue`
  - `resources/js/pages/admin/users/Edit.vue`
  - `resources/js/pages/admin/roles/Index.vue`

**Consumes:**
- P2.2's Inertia props and routes (see P2.2 **Produces**).
- `Paginated<T>` from `@/types/tickets`.
- `NavItem` from `@/config/navigation`.
- The shared `auth.can.manageUsers`.
- Existing shadcn-vue components only: `button`, `card`, `input`, `label`, `select`, `checkbox`, `table`, `badge`. No `npx shadcn-vue add` is needed.
- Paths are literal strings, as on the ticket pages.

**Produces:**
- `types/users.ts` exports `RoleValue`, `RoleOption`, `CategoryOption`, `AdminUser`, `UserFilters`, `PermissionOption`, `RolePermissions`. P5 imports them.
- `navigation/admin.ts` default-exports two entries:
  - `Users` (order 72)
  - `Roles & permissions` (order 73)

  Both are visible when `auth.can.manageUsers` is true. P5 may append the Categories (70), Holidays (71) and Imports entries.

**Steps:**

1. **Create** `resources/js/types/users.ts`.

```ts
export type RoleValue = 'admin' | 'agent' | 'customer';

export type RoleOption = { value: RoleValue; label: string };

export type CategoryOption = { id: number; name: string; is_active: boolean };

export type AdminUser = {
    id: number;
    name: string;
    email: string;
    role: RoleValue | null;
    is_available: boolean;
    last_assigned_at: string | null;
    categories?: { id: number; name: string }[];
    created_at: string | null;
};

export type UserFilters = { q: string | null; role: RoleValue | null };

export type PermissionOption = { value: string; label: string };

export type RolePermissions = {
    value: RoleValue;
    label: string;
    permissions: string[];
};
```

2. **Create** `resources/js/config/navigation/admin.ts`.

```ts
import type { NavItem } from '@/config/navigation';
import { ShieldCheck, Users } from '@lucide/vue';

const items: NavItem[] = [
    {
        title: 'Users',
        href: '/admin/users',
        icon: Users,
        order: 72,
        visible: (page) => page.auth.can.manageUsers,
    },
    {
        title: 'Roles & permissions',
        href: '/admin/roles',
        icon: ShieldCheck,
        order: 73,
        visible: (page) => page.auth.can.manageUsers,
    },
];

export default items;
```

3. **Create** `resources/js/components/admin/UserForm.vue`. The agent profile (availability and categories) only appears for the agent role. When `isSelf` is true, the role picker is disabled; the server enforces the same rule.

```vue
<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type {
    AdminUser,
    CategoryOption,
    RoleOption,
    RoleValue,
} from '@/types/users';
import { Link, useForm } from '@inertiajs/vue3';

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

const onRoleChange = (value: unknown): void => {
    form.role = String(value) as RoleValue;
};

const toggleCategory = (id: number, checked: boolean): void => {
    form.category_ids = checked
        ? [...form.category_ids, id]
        : form.category_ids.filter((categoryId) => categoryId !== id);
};

const submit = (): void => {
    if (props.user) {
        form.patch(`/admin/users/${props.user.id}`, {
            onSuccess: () => form.reset('password'),
        });

        return;
    }

    form.post('/admin/users');
};
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
            <Select
                :model-value="form.role"
                :disabled="isSelf"
                @update:model-value="onRoleChange"
            >
                <SelectTrigger id="role"><SelectValue /></SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="role in roles"
                        :key="role.value"
                        :value="role.value"
                    >
                        {{ role.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
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

            <Label for="is_available" class="flex items-center gap-2">
                <Checkbox
                    id="is_available"
                    :model-value="form.is_available"
                    @update:model-value="
                        (checked) => (form.is_available = checked === true)
                    "
                />
                <span>Available for automatic assignment</span>
            </Label>
            <InputError :message="form.errors.is_available" />

            <div class="grid gap-2">
                <span class="text-sm font-medium">Categories</span>
                <p
                    v-if="categories.length === 0"
                    class="text-xs text-muted-foreground"
                >
                    No categories exist yet.
                </p>
                <Label
                    v-for="category in categories"
                    :key="category.id"
                    :for="`category-${category.id}`"
                    class="flex items-center gap-2 font-normal"
                >
                    <Checkbox
                        :id="`category-${category.id}`"
                        :model-value="form.category_ids.includes(category.id)"
                        @update:model-value="
                            (checked) =>
                                toggleCategory(category.id, checked === true)
                        "
                    />
                    <span>{{ category.name }}</span>
                    <span
                        v-if="!category.is_active"
                        class="text-xs text-muted-foreground"
                        >(inactive)</span
                    >
                </Label>
                <InputError :message="form.errors.category_ids" />
            </div>
        </fieldset>

        <div class="flex gap-2">
            <Button type="submit" :disabled="form.processing">{{
                user ? 'Save changes' : 'Create user'
            }}</Button>
            <Button variant="ghost" as-child>
                <Link href="/admin/users">Cancel</Link>
            </Button>
        </div>
    </form>
</template>
```

4. **Create** `resources/js/pages/admin/users/Index.vue`. Changing the role filter applies it immediately, and the search applies on submit. The list layout and pagination follow `pages/tickets/Index.vue`.

```vue
<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { Paginated } from '@/types/tickets';
import type { AdminUser, RoleOption, UserFilters } from '@/types/users';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Users', href: '/admin/users' }],
    },
});

const props = defineProps<{
    users: Paginated<AdminUser>;
    filters: UserFilters;
    roles: RoleOption[];
}>();

const search = ref(props.filters.q ?? '');
const role = ref<string>(props.filters.role ?? 'all');

const applyFilters = (): void => {
    const query: Record<string, string> = {};

    if (search.value !== '') {
        query.q = search.value;
    }

    if (role.value !== 'all') {
        query.role = role.value;
    }

    router.get('/admin/users', query, { preserveState: true, replace: true });
};

const onRoleChange = (value: unknown): void => {
    role.value = String(value ?? 'all');
    applyFilters();
};

const roleLabel = (value: string | null): string =>
    props.roles.find((option) => option.value === value)?.label ?? 'No role';

const agentProfile = (user: AdminUser): string => {
    const categories =
        user.categories?.map((category) => category.name).join(', ') ||
        'no categories';

    return `${user.is_available ? 'Available' : 'Unavailable'} · ${categories}`;
};
</script>

<template>
    <Head title="Users" />

    <div class="flex flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-xl font-semibold">Users</h1>
            <Button as-child size="sm">
                <Link href="/admin/users/create">New user</Link>
            </Button>
        </div>

        <form class="flex flex-wrap gap-2" @submit.prevent="applyFilters">
            <Input
                v-model="search"
                type="search"
                placeholder="Name or email starts with…"
                class="max-w-xs"
                aria-label="Search users"
            />
            <Select :model-value="role" @update:model-value="onRoleChange">
                <SelectTrigger class="w-44" aria-label="Filter by role">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All roles</SelectItem>
                    <SelectItem
                        v-for="option in roles"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <Button type="submit" variant="outline">Search</Button>
        </form>

        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>Name</TableHead>
                    <TableHead>Email</TableHead>
                    <TableHead>Role</TableHead>
                    <TableHead>Agent profile</TableHead>
                    <TableHead class="text-right">Actions</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableRow v-if="props.users.data.length === 0">
                    <TableCell
                        colspan="5"
                        class="text-center text-muted-foreground"
                        >No users match.</TableCell
                    >
                </TableRow>
                <TableRow v-for="user in props.users.data" :key="user.id">
                    <TableCell class="font-medium">{{ user.name }}</TableCell>
                    <TableCell>{{ user.email }}</TableCell>
                    <TableCell>
                        <Badge variant="secondary">{{
                            roleLabel(user.role)
                        }}</Badge>
                    </TableCell>
                    <TableCell class="text-xs text-muted-foreground">{{
                        user.role === 'agent' ? agentProfile(user) : '—'
                    }}</TableCell>
                    <TableCell class="text-right">
                        <Button as-child size="sm" variant="outline">
                            <Link :href="`/admin/users/${user.id}/edit`"
                                >Edit</Link
                            >
                        </Button>
                    </TableCell>
                </TableRow>
            </TableBody>
        </Table>

        <div class="flex items-center justify-between text-sm">
            <span class="text-muted-foreground">
                Page {{ props.users.meta.current_page }} of
                {{ props.users.meta.last_page }} ·
                {{ props.users.meta.total }} users
            </span>
            <div class="flex gap-2">
                <Button v-if="props.users.links.prev" as-child size="sm" variant="outline">
                    <Link :href="props.users.links.prev" preserve-scroll>Previous</Link>
                </Button>
                <Button v-if="props.users.links.next" as-child size="sm" variant="outline">
                    <Link :href="props.users.links.next" preserve-scroll>Next</Link>
                </Button>
            </div>
        </div>
    </div>
</template>
```

5. **Create** `resources/js/pages/admin/users/Create.vue` and `Edit.vue`. Both follow the layout of `pages/tickets/Create.vue`: `<Head>`, then `<div class="p-4"><Card class="max-w-xl">` with a `CardHeader`/`CardTitle` and the `UserForm` inside `CardContent`.
   - **Create:**
     - props `roles: RoleOption[]` and `categories: CategoryOption[]`
     - title `New user`
     - static breadcrumbs through `defineOptions({ layout: { breadcrumbs: [{ title: 'Users', href: '/admin/users' }, { title: 'New user', href: '/admin/users/create' }] } })`
     - renders `<UserForm :roles="roles" :categories="categories" />`
   - **Edit:**
     - props `user: AdminUser`, `roles: RoleOption[]`, `categories: CategoryOption[]`, `isSelf: boolean`
     - head title `` `Edit ${user.name}` `` and card title `Edit user`
     - renders `<UserForm :user="user" :roles="roles" :categories="categories" :is-self="isSelf" />`
     - the breadcrumbs depend on props, so they are set as on `tickets/Show.vue`:

```ts
watchEffect(() => {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Users', href: '/admin/users' },
        { title: props.user.name, href: `/admin/users/${props.user.id}/edit` },
    ];
    setLayoutProps({ breadcrumbs });
});
```

6. **Create** `resources/js/pages/admin/roles/Index.vue`. It is a read-only matrix: one row per permission and one column per role.

```vue
<script setup lang="ts">
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { PermissionOption, RolePermissions } from '@/types/users';
import { Head, Link } from '@inertiajs/vue3';
import { Check, Minus } from '@lucide/vue';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Roles & permissions', href: '/admin/roles' }],
    },
});

const props = defineProps<{
    roles: RolePermissions[];
    permissions: PermissionOption[];
}>();

const grants = (role: RolePermissions, permission: string): boolean =>
    role.permissions.includes(permission);
</script>

<template>
    <Head title="Roles & permissions" />

    <div class="p-4">
        <Card>
            <CardHeader>
                <CardTitle>Roles &amp; permissions</CardTitle>
                <CardDescription>
                    Roles are fixed and their permissions are read-only. To
                    change what someone can do, change their role on the
                    <Link
                        href="/admin/users"
                        class="underline underline-offset-4"
                        >Users</Link
                    >
                    page.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Permission</TableHead>
                            <TableHead
                                v-for="role in props.roles"
                                :key="role.value"
                                class="text-center"
                                >{{ role.label }}</TableHead
                            >
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="permission in props.permissions"
                            :key="permission.value"
                        >
                            <TableCell>
                                <div class="font-medium">
                                    {{ permission.label }}
                                </div>
                                <div
                                    class="font-mono text-xs text-muted-foreground"
                                >
                                    {{ permission.value }}
                                </div>
                            </TableCell>
                            <TableCell
                                v-for="role in props.roles"
                                :key="role.value"
                                class="text-center"
                            >
                                <Check
                                    v-if="grants(role, permission.value)"
                                    class="mx-auto size-4 text-green-600"
                                    aria-hidden="true"
                                />
                                <Minus
                                    v-else
                                    class="mx-auto size-4 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <span class="sr-only">{{
                                    grants(role, permission.value)
                                        ? 'Granted'
                                        : 'Not granted'
                                }}</span>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    </div>
</template>
```

7. **Format:** `npx vp fmt resources/js/types/users.ts resources/js/config/navigation/admin.ts resources/js/components/admin resources/js/pages/admin`. There are no PHP files and no tests. The session gate (`npm run build && npm run check && npx vue-tsc --noEmit`) type-checks the pages, and the browser check exercises them.

**Proposed commit:** `feat(ui): add user, role and permission admin pages`
`Refs: P2.3` · `Implements: TD-002, TD-010`

---

## P2.4 — Authorization-matrix rows and route-protection audit

**Files:**
- Create:
  - `tests/Support/RouteAudit.php`
  - `tests/Feature/Authorization/RouteProtectionTest.php`
- Modify:
  - `tests/Feature/Authorization/AuthorizationMatrixTest.php` (append)

**Consumes:**
- The P2.1 and P2.2 routes.
- `Tests\Support\AuthorizationMatrix` (T04).

**Produces:**
- `Tests\Support\RouteAudit`, a contract for P3, P4 and P6:
  - `PUBLIC` lists routes that are public by design, keyed `"METHOD uri"`.
  - `AUTHENTICATED_ONLY` lists routes that any signed-in user may call without a Policy.
  - `bindings()` maps each route parameter to a closure that creates a real model and returns its route key.
- `applicationRoutes()` lists only routes whose action is an `App\` controller or a closure defined under `routes/`. Vendor routes are excluded: Boost, Inertia devtools, Scramble, `storage/*`, `up`, `sanctum/csrf-cookie`.
- **Rule for later plans:** a new route parameter (`{attachment}`, `{category}` and so on) needs a `bindings()` entry, or the audit throws and names the parameter. A route without a Policy fails the audit. Only a deliberate decision can add a route to `AUTHENTICATED_ONLY`.
- `GET dashboard` is in `AUTHENTICATED_ONLY` until P4 adds the `viewDashboard` gate to it. P4 must remove that entry. It must also change `tests/Feature/App/InertiaSharedPropsTest.php`, which requests `dashboard` as a user without permissions.

**Steps:**

1. **Create** `tests/Support/RouteAudit.php`.

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Ticket;
use App\Models\User;
use Closure;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use LogicException;
use ReflectionFunction;

final class RouteAudit
{
    public const PUBLIC = [
        'GET /',
        'GET login',
        'POST login',
        'GET register',
        'POST register',
        'GET forgot-password',
        'POST forgot-password',
        'GET reset-password/{token}',
        'POST reset-password',
        'POST api/v1/auth/tokens',
    ];

    public const AUTHENTICATED_ONLY = [
        'POST logout',
        'GET api/v1/me',
        'DELETE api/v1/auth/tokens/current',
        'GET dashboard',
    ];

    /** @return array<string, Closure(): string> */
    public static function bindings(): array
    {
        return [
            'ticket' => static fn (): string => Ticket::factory()->create()->number,
            'user' => static fn (): string => (string) User::factory()->customer()->create()->id,
        ];
    }

    /** @return list<Route> */
    public static function applicationRoutes(): array
    {
        return array_values(array_filter(
            Router::getRoutes()->getRoutes(),
            static fn (Route $route): bool => self::isApplicationRoute($route),
        ));
    }

    public static function key(Route $route): string
    {
        $methods = array_values(array_diff($route->methods(), ['HEAD']));

        return $methods[0].' '.$route->uri();
    }

    public static function method(Route $route): string
    {
        return explode(' ', self::key($route))[0];
    }

    public static function isApi(Route $route): bool
    {
        return str_starts_with($route->uri(), 'api/');
    }

    public static function uriFor(Route $route): string
    {
        $bindings = self::bindings();

        $uri = preg_replace_callback('/\{(\w+)\??\}/', static function (array $match) use ($bindings, $route): string {
            $binding = $bindings[$match[1]]
                ?? throw new LogicException("Add a RouteAudit binding for {{$match[1]}}, used by [".self::key($route).'].');

            return $binding();
        }, $route->uri());

        return '/'.ltrim((string) $uri, '/');
    }

    private static function isApplicationRoute(Route $route): bool
    {
        $uses = $route->getAction('uses');

        if ($uses instanceof Closure) {
            return str_starts_with((string) (new ReflectionFunction($uses))->getFileName(), base_path('routes'));
        }

        return is_string($uses) && str_starts_with($uses, 'App\\');
    }
}
```

2. **Create** `tests/Feature/Authorization/RouteProtectionTest.php`. It is the route-protection audit. Guests must be turned away from every non-public route: a web route redirects to `login`, an API route answers 401. A signed-in user with no role, and therefore no permission, must get 403 or 404 from every route that isn't public or authentication-only, which proves a Policy guards it (TD-120). Each check collects offenders, so one failure lists every unguarded route.

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Support\RouteAudit;

it('keeps the public and authentication-only lists in step with the real routes', function (): void {
    $keys = array_map(RouteAudit::key(...), RouteAudit::applicationRoutes());

    expect(array_values(array_diff([...RouteAudit::PUBLIC, ...RouteAudit::AUTHENTICATED_ONLY], $keys)))->toBe([]);
});

it('turns guests away from every route that is not public by design', function (): void {
    $offenders = [];

    foreach (RouteAudit::applicationRoutes() as $route) {
        if (in_array(RouteAudit::key($route), RouteAudit::PUBLIC, true)) {
            continue;
        }

        app('auth')->forgetGuards();
        $uri = RouteAudit::uriFor($route);

        if (RouteAudit::isApi($route)) {
            $status = $this->json(RouteAudit::method($route), $uri)->status();
            $refused = $status === 401;
        } else {
            $response = $this->call(RouteAudit::method($route), $uri);
            $status = $response->status();
            $refused = $status === 302 && $response->headers->get('Location') === route('login');
        }

        if (! $refused) {
            $offenders[] = RouteAudit::key($route)." → {$status}";
        }
    }

    expect($offenders)->toBe([]);
});

it('denies a user without any role on every route that needs a permission', function (): void {
    $user = User::factory()->create();
    $offenders = [];

    foreach (RouteAudit::applicationRoutes() as $route) {
        if (in_array(RouteAudit::key($route), [...RouteAudit::PUBLIC, ...RouteAudit::AUTHENTICATED_ONLY], true)) {
            continue;
        }

        app('auth')->forgetGuards();
        $uri = RouteAudit::uriFor($route);

        if (RouteAudit::isApi($route)) {
            Sanctum::actingAs($user, ['*']);
            $status = $this->json(RouteAudit::method($route), $uri)->status();
        } else {
            $status = $this->actingAs($user)->call(RouteAudit::method($route), $uri)->status();
        }

        if (! in_array($status, [403, 404], true)) {
            $offenders[] = RouteAudit::key($route)." → {$status}";
        }
    }

    expect($offenders)->toBe([]);
});
```

3. **Append** to `tests/Feature/Authorization/AuthorizationMatrixTest.php` the per-role rows for every route this plan adds. Add `use App\Models\User;` if it's missing; `Role` and `AuthorizationMatrix` are already imported. Writes carry a valid payload, so the admin rows prove success. Non-admins get 403 before validation, because the `can:` middleware runs first.

```php
dataset('user administration routes', [
    'web users index' => ['GET', 'admin.users.index', false, 200],
    'web users create' => ['GET', 'admin.users.create', false, 200],
    'web users store' => ['POST', 'admin.users.store', false, 302],
    'web users edit' => ['GET', 'admin.users.edit', true, 200],
    'web users update' => ['PATCH', 'admin.users.update', true, 302],
    'web roles index' => ['GET', 'admin.roles.index', false, 200],
    'api users index' => ['GET', 'api.v1.admin.users.index', false, 200],
    'api users store' => ['POST', 'api.v1.admin.users.store', false, 201],
    'api users update' => ['PATCH', 'api.v1.admin.users.update', true, 200],
    'api roles index' => ['GET', 'api.v1.admin.roles.index', false, 200],
]);

it('lets only admins manage users and read the permission matrix', function (string $method, string $name, bool $needsUser, int $adminStatus, Role $role): void {
    $matrix = AuthorizationMatrix::for($this);
    $matrix->actingAsRole($role);
    $target = User::factory()->customer()->create();
    $uri = $needsUser ? route($name, $target) : route($name);
    $payload = $method === 'GET' ? [] : [
        'name' => 'Matrix User',
        'email' => 'matrix.user@example.com',
        'password' => 'password',
        'role' => Role::Customer->value,
    ];

    $matrix->expectAccess($method, $uri, $role === Role::Admin ? $adminStatus : 403, $payload);
})->with('user administration routes')->with([Role::Admin, Role::Agent, Role::Customer]);

it('sends signed-in users away from the password reset screens', function (string $method, string $uri, Role $role): void {
    $matrix = AuthorizationMatrix::for($this);
    $matrix->actingAsRole($role);

    $matrix->expectAccess($method, $uri, 302)->assertRedirect(route('home'));
})->with([
    'forgot form' => ['GET', '/forgot-password'],
    'send link' => ['POST', '/forgot-password'],
    'reset form' => ['GET', '/reset-password/some-token'],
    'reset' => ['POST', '/reset-password'],
])->with([Role::Admin, Role::Customer]);
```

4. **Run them and expect PASS.** Both tests lock in behaviour that is already built, so they pass on the first run. If the audit lists an offender, fix that route's protection; never relax the audit.
   `DB_DATABASE=techzu_testing_agent<N> ./vendor/bin/pest tests/Feature/Authorization/RouteProtectionTest.php tests/Feature/Authorization/AuthorizationMatrixTest.php`

5. **Prove the audit can fail.**
   1. Temporarily remove `->can('viewAny', User::class)` from the web `admin.roles.index` route.
   2. Run `DB_DATABASE=techzu_testing_agent<N> ./vendor/bin/pest tests/Feature/Authorization/RouteProtectionTest.php --filter="denies a user without any role"`. It should fail and list `GET admin/roles → 200`.
   3. Restore the line and run it again. It should pass. `git diff routes/` must then be empty apart from P2.2's new files.

6. **Format:** `./vendor/bin/pint tests/Support/RouteAudit.php tests/Feature/Authorization`

**Proposed commit:** `test(auth): audit route protection and cover user admin routes`
`Refs: P2.4` · `Implements: TD-012, TD-120`

---

## Acceptance criteria

1. **Login and register.** Login, logout, customer self-registration and the Sanctum token endpoints behave as before. Their existing tests still pass unchanged.
2. **Login page.** It shows a "Forgot password?" link to `password.request`, and it displays `flash.success`.
3. **Reset routes.**
   - `GET /forgot-password` renders `auth/ForgotPassword`.
   - `POST /forgot-password` always redirects back with the same success text and no error, whether or not the email exists.
   - A reset notification is sent only to an existing user, and its link equals `route('password.reset', ['token' => …, 'email' => …])`.
4. **Reset itself.**
   - `GET /reset-password/{token}?email=` renders `auth/ResetPassword` with `token` and `email`.
   - `POST /reset-password` with a valid token changes the password, rotates the remember token, deletes every Sanctum token of that user, and redirects to `login` with a success flash.
   - An invalid token, or an unknown email, gives the same `email` error and changes nothing.
5. **Throttling.** Both reset POST routes allow 5 requests per minute and answer the 6th with 429. All four reset routes are guest-only.
6. **Registration.** It ignores `role` and `roles` input. A new account is always exactly `customer`.
7. **Admin users list.** `admin.users.index` and `api.v1.admin.users.index` return a page of `AdminUserResource`:
   - sorted by name, then id
   - filtered by `role` and by `q` (a prefix of name or email, with `%` and `_` treated literally)
   - with `per_page` capped at 100
   - with no N+1 queries, and no password or token fields
8. **Create and edit.**
   - Admins can create users (web and API) and edit them (web and API), with role, name, email, optional password and, for agents, categories and availability.
   - There is no delete route.
   - Unknown roles, duplicate emails and unknown category ids are rejected with 422 or session errors.
9. **Role changes.**
   - `ChangeUserRole` is the only code that calls `syncRoles`.
   - The user ends with exactly one role, categories are detached unless the new role is agent, and the user's tokens are revoked.
   - Keeping the same role is a no-op.
10. **No self-demotion.**
    - An admin cannot change their own role: `CannotChangeOwnRole` (422, `cannot_change_own_role`) is thrown and the whole update rolls back.
    - The web shows it as a flash error, and the API returns `{message, code}` with 422.
    - The edit page disables the role picker when `isSelf` is true.
11. **Admin passwords.** A new password set by an admin revokes the user's tokens. A blank password keeps the old one.
12. **Permission matrix.**
    - `admin.roles.index` renders `admin/roles/Index`, and `api.v1.admin.roles.index` returns `RoleResource` rows.
    - Roles appear in the order Admin, Agent, Customer, and each shows exactly the permissions in the seeded tables, which match `Permission::forRole`.
    - Nothing on that page or endpoint can change a permission.
13. **Route authorization.** Every P2 admin route carries `can:` middleware against `UserPolicy`. Admins get success, while agents and customers get 403 on web and API alike.
14. **Route audit.** `RouteProtectionTest` passes:
    - every application route outside `RouteAudit::PUBLIC` turns guests away (302 to `login` on the web, 401 on the API)
    - every route outside `PUBLIC` and `AUTHENTICATED_ONLY` gives a user without a role 403 or 404
    - both lists contain only routes that exist
15. **Navigation.** `navigation/admin.ts` shows "Users" and "Roles & permissions" only when `auth.can.manageUsers` is true.
16. **Pages.** The users index (search, role filter, pagination), create, edit, and roles pages use only existing shadcn-vue components. They contain no `v-html` and no code comments.
17. **Gate.** The session gate passes: `pint --test`, `phpstan`, `pest --parallel`, `npm run build`, `npm run check` and `vue-tsc`.
