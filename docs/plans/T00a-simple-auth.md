# T00a — Simple authentication: replace Fortify with plain session auth

**Objective:** Remove `laravel/fortify` and everything it brought (two-factor auth, passkeys, email verification, password reset, password confirmation, the profile/security/appearance settings pages), and replace it with plain session login, logout and customer self-registration: two thin controllers, two FormRequests and one Action. T00's Sanctum token API stays.

- **Depends on:** T00 (landed on `main`)
- **Wave:** 0b, alone. Runs after T00 and before T01.
- **TDs implemented:** TD-002 (amended 2026-10-29: Fortify removed by owner decision), TD-001 (thin controllers, one Action per use case), TD-120 (login throttling, explicit `$fillable`, no role from request data)

The PRD asks for none of the removed features (and says not to use components for their own sake, PRD line 195). Simplicity is the goal: no validation-rule traits, each FormRequest holds its own `rules()`, no extra abstraction.

---

## Files

**Create**
- `routes/web/auth.php`
- `app/Http/Controllers/Auth/LoginController.php`
- `app/Http/Controllers/Auth/RegisterController.php`
- `app/Http/Requests/Auth/LoginRequest.php`
- `app/Http/Requests/Auth/RegisterRequest.php`
- `app/Actions/Auth/RegisterCustomer.php`

**Modify**
- `composer.json`, `composer.lock` (`laravel/fortify` removed, with the 14 packages only it needed)
- `package.json`, `package-lock.json` (`@laravel/passkeys` and `vue-input-otp` removed)
- `bootstrap/providers.php` (`FortifyServiceProvider` removed)
- `bootstrap/app.php`: T00a.1 removes the `HandleAppearance` middleware and the `appearance` cookie exception; T00a.2 adds `redirectUsersTo(fn (): string => route('home'))`. Nothing else changes.
- `routes/web.php` (the `require __DIR__.'/settings.php';` line removed)
- `routes/web/dashboard.php` (`['auth', 'verified']` becomes `['auth']`)
- `app/Models/User.php` (replaced: no Fortify contracts or traits, no two-factor or `email_verified_at` attributes)
- `database/factories/UserFactory.php` (replaced: no two-factor columns, no `email_verified_at`, no `unverified()`/`withTwoFactor()` states)
- `database/migrations/0001_01_01_000000_create_users_table.php` (replaced: no `email_verified_at` column, no `password_reset_tokens` table). **One-off, documented exception** to "don't edit migrations from earlier tasks": nothing has been deployed, so the schema is edited instead of amended, and every database is rebuilt with `php artisan migrate:fresh`.
- `app/Http/Middleware/HandleInertiaRequests.php` (the `email_verified_at` line only)
- `app/Actions/Auth/IssueApiToken.php` (the `hasVerifiedEmail()` check only; see Contract note 2)
- `tests/Feature/App/InertiaSharedPropsTest.php` (the `email_verified_at` assertion only)
- `tests/Feature/Api/V1/AuthTokenTest.php` (the `'unverified email'` dataset row only)
- `tests/Feature/Auth/EmailVerificationTest.php` (subtask 1 only: one route name; subtask 2 deletes the file)
- `tests/TestCase.php` (the Fortify-only `skipUnlessFortifyHas()` helper removed; see Contract note 3)
- `resources/js/app.ts` (no settings layout, no theme initialisation, no `Welcome` case)
- `resources/views/app.blade.php` (replaced: no appearance cookie; the page follows the OS colour scheme)
- `resources/js/components/UserMenuContent.vue` (the Settings link removed; logout kept)
- `resources/js/pages/auth/Login.vue`, `resources/js/pages/auth/Register.vue` (simplified, posting to the new controllers)
- `resources/js/types/auth.ts` (no `email_verified_at`, no `Passkey`, no `TwoFactorConfigContent`)
- `resources/js/types/ui.ts` (no `Appearance`/`ResolvedAppearance`)

**Delete** (real paths on `main` after T00)
- Settings backend: `app/Http/Controllers/Settings/ProfileController.php`, `app/Http/Controllers/Settings/SecurityController.php`, `app/Http/Requests/Settings/PasswordUpdateRequest.php`, `app/Http/Requests/Settings/ProfileDeleteRequest.php`, `app/Http/Requests/Settings/ProfileUpdateRequest.php`, `app/Http/Requests/Settings/TwoFactorAuthenticationRequest.php`, `routes/settings.php`
- Settings frontend: `resources/js/pages/settings/Appearance.vue`, `resources/js/pages/settings/Profile.vue`, `resources/js/pages/settings/Security.vue`, `resources/js/layouts/settings/Layout.vue`
- Components and composables used only by removed pages: `resources/js/components/AlertError.vue`, `resources/js/components/AppearanceTabs.vue`, `resources/js/components/DeleteUser.vue`, `resources/js/components/Heading.vue`, `resources/js/components/ManagePasskeys.vue`, `resources/js/components/ManageTwoFactor.vue`, `resources/js/components/PasskeyItem.vue`, `resources/js/components/PasskeyRegister.vue`, `resources/js/components/PasskeyVerify.vue`, `resources/js/components/TwoFactorRecoveryCodes.vue`, `resources/js/components/TwoFactorSetupModal.vue`, `resources/js/components/ui/input-otp/` (`index.ts`, `InputOTP.vue`, `InputOTPGroup.vue`, `InputOTPSeparator.vue`, `InputOTPSlot.vue`), `resources/js/composables/useAppearance.ts`, `resources/js/composables/useTwoFactorAuth.ts`
- Appearance backend: `app/Http/Middleware/HandleAppearance.php`
- Fortify backend: `app/Providers/FortifyServiceProvider.php`, `config/fortify.php`, `app/Actions/Fortify/CreateNewUser.php`, `app/Actions/Fortify/ResetUserPassword.php`, `app/Concerns/PasswordValidationRules.php`, `app/Concerns/ProfileValidationRules.php`
- Fortify pages: `resources/js/pages/auth/ConfirmPassword.vue`, `resources/js/pages/auth/ForgotPassword.vue`, `resources/js/pages/auth/ResetPassword.vue`, `resources/js/pages/auth/TwoFactorChallenge.vue`, `resources/js/pages/auth/VerifyEmail.vue`, and `resources/js/pages/Welcome.vue` (`/` sends guests to `login`, so it is never shown)
- Migrations: `database/migrations/2024_01_01_000000_create_passkeys_table.php`, `database/migrations/2025_08_14_170933_add_two_factor_columns_to_users_table.php`
- Kit tests for removed features: `tests/Feature/Settings/ProfileUpdateTest.php`, `tests/Feature/Settings/SecurityTest.php`, and every kit file in `tests/Feature/Auth/`: `AuthenticationTest.php`, `EmailVerificationTest.php`, `PasswordConfirmationTest.php`, `PasswordResetTest.php`, `RegistrationTest.php`, `TwoFactorChallengeTest.php`, `VerificationNotificationTest.php`. The two that cover kept behaviour (`AuthenticationTest`, `RegistrationTest`) are Fortify-bound PHPUnit classes and are replaced by the Pest files below.
- `tests/Feature/DashboardTest.php`: `RouteLoadingTest` covers the `dashboard` route in the `web` group, `InertiaSharedPropsTest` renders it for a signed-in user, and `HomeRedirectTest` covers the guest redirect at `/`.

**Kept unchanged on purpose**
- `resources/js/layouts/AuthLayout.vue` and `resources/js/layouts/auth/*` (the auth layout stays), `resources/js/components/NavUser.vue` (it has no settings link; the menu lives in `UserMenuContent.vue`), `AppHeader.vue`, `AppSidebar.vue`, `TextLink.vue`, `PasswordInput.vue`, `InputError.vue`, `PlaceholderPattern.vue`, every other `components/ui/*` primitive (`dialog`, `alert`, … stay for later tasks).
- `app/Http/Controllers/HomeController.php`, `tests/Feature/App/HomeRedirectTest.php`, `tests/Feature/App/RouteLoadingTest.php`, the API token code apart from the one check in `IssueApiToken`.
- `config/auth.php`: the kit's `passwords.users` broker still names a `password_reset_tokens` table. Nothing uses the broker any more; it is framework default config and isn't edited.
- `database/seeders/DatabaseSeeder.php` (its `test@example.com` / `password` user is what the browser check signs in with).
- `docs/DEVELOPMENT.md` (its kit-scaffold paragraph is history: it records what `install:features` kept at scaffold time).

**Test**
- `tests/Feature/Auth/LoginTest.php`
- `tests/Feature/Auth/RegisterTest.php`

**Must not touch**
- `PRD.md`, `TECHNICAL_DECISIONS.md` (the controller has already amended TD-002), `CLAUDE.md`, `docs/plans/**`
- `app/Http/Controllers/HomeController.php`, `routes/api/v1/auth.php`, `resources/js/config/navigation.ts`
- any migration other than the three named above
- `docs/FEATURES.md`, except the documenter's own section (README §2 step 7; see Contract note 7)

---

## Interfaces

**Consumes** (T00, on `main`): `HomeController` (`home`), the `dashboard` placeholder route, `HandleInertiaRequests`, `IssueApiToken`, the kit's auth layout, Wayfinder.

**Produces** (exact names; later plans rely on them)

- `routes/web/auth.php`, loaded by T00's `routes/web/*.php` glob inside the `web` group:

  | Method | URI | Action | Name | Middleware |
  |---|---|---|---|---|
  | GET | `login` | `LoginController@create` | `login` | `guest` |
  | POST | `login` | `LoginController@store` | — | `guest`, `throttle:5,1` |
  | POST | `logout` | `LoginController@destroy` | `logout` | `auth` |
  | GET | `register` | `RegisterController@create` | `register` | `guest` |
  | POST | `register` | `RegisterController@store` | — | `guest` |

- `App\Http\Controllers\Auth\LoginController` (final):
  - `create(): Inertia\Response` renders `auth/Login` (no props)
  - `store(LoginRequest $request): RedirectResponse`: `Auth::attempt($request->safe()->only(['email', 'password']), $request->boolean('remember'))`; on failure throws `ValidationException` on `email` with `__('auth.failed')`; on success `$request->session()->regenerate()` and `redirect()->intended(route('home'))`
  - `destroy(Request $request): RedirectResponse`: logout, invalidate the session, regenerate the CSRF token, redirect to `route('login')`
- `App\Http\Controllers\Auth\RegisterController` (final):
  - `create(): Inertia\Response` renders `auth/Register` (no props)
  - `store(RegisterRequest $request, RegisterCustomer $register): RedirectResponse`: creates the user through the Action, then `Auth::login($user)`, `$request->session()->regenerate()`, `redirect()->route('home')`
- `App\Http\Requests\Auth\LoginRequest`: `email` required|string|email, `password` required|string, `remember` boolean
- `App\Http\Requests\Auth\RegisterRequest`: `name` required|string|max:255, `email` required|string|lowercase|email|max:255|unique:users, `password` required|string|confirmed|`Password::defaults()`
- `App\Actions\Auth\RegisterCustomer` (final): `handle(string $name, string $email, string $password): User`. Creates the user inside `DB::transaction()` (TD-053), so the role line T01 adds is atomic with the insert. The password is hashed by the model's `hashed` cast. **T00a assigns no role; T01 adds `->assignRole(Role::Customer)`.**
- Shared props: `auth.user` is exactly `{id, name, email, roles}`; `email_verified_at` is gone (PHP and `resources/js/types/auth.ts`).
- Frontend: `auth/Login.vue` and `auth/Register.vue` post through Wayfinder's controller helpers `@/actions/App/Http/Controllers/Auth/LoginController` and `.../RegisterController` (`store.form()`), because the POST routes are unnamed. Links use `login()`, `register()`, `logout()` and `home()` from `@/routes`.
- No route uses the `verified` middleware. `bootstrap/providers.php` holds only `AppServiceProvider`.
- Public web routes after T00a: `home` (`/`), `login`, `register` and `up`. The guest-only matcher is plain `guest`.

---

## Subtasks & mini-waves

| Subtask | Files | Depends on | Parallel-safe with |
|---|---|---|---|
| T00a.1 Remove the settings pages and the appearance preference | settings backend + routes, settings pages + layout, the components/composables listed for it, `HandleAppearance.php`, `bootstrap/app.php`, `routes/web.php`, `resources/js/app.ts`, `resources/views/app.blade.php`, `resources/js/types/ui.ts`, `UserMenuContent.vue`, `tests/Feature/Settings/*`, `tests/Feature/Auth/EmailVerificationTest.php` (one line) | — | — |
| T00a.2 Replace Fortify with plain session authentication | everything else in **Files** | T00a.1 | — |

Mini-waves: **A** = T00a.1, **B** = T00a.2. Strictly sequential: both touch `resources/js/app.ts`, and the settings pages call Fortify routes, so they must be gone before Fortify is.

Why two commits and not more: the settings pages depend on Fortify's routes (two-factor, passkeys, verification), so they can be removed while Fortify is still installed, and that commit stays green on its own. Everything else is one coupled change: once `laravel/fortify` leaves `composer.json`, its provider, config, traits on `User`, pages, tests and schema all have to go in the same commit for the gate to pass, and login/registration must be replaced in that same commit or the app has no way in.

**Environment.** Everything runs in the task worktree (README §2 step 1) against the existing MySQL server at `127.0.0.1:3306`. Never start a `mysqld`.
- Worktree `.worktrees/T00a`, branch `task/T00a-simple-auth`, databases `techzu_wt_t00a` (dev) and `techzu_wt_t00a_testing` (tests), `APP_URL=http://127.0.0.1:8100` (T00 has 8000 and T01 has 8001, so T00a takes 8100).
- `.env` is copied from `.env.example`, whose `APP_KEY` is blank: run `php artisan key:generate` once after `composer install`. Without a key every HTTP test fails with `No application encryption key has been specified.` (`phpunit.xml` doesn't set one).
- Every Pest command below is run as `DB_DATABASE=techzu_wt_t00a_testing ./vendor/bin/pest ...`; it's shortened to `./vendor/bin/pest` here.

Agent output: `laravel/pao` prints one JSON line per tool when run under an agent (`{"tool":"pest","result":"passed","tests":N,...}`). The expected outputs below are in that form.

Wayfinder's `resources/js/{actions,routes,wayfinder}` are generated and gitignored. They still hold helpers for Fortify and the settings routes from the last build, so each subtask deletes them before `npm run build` regenerates them. The build fails on any import of a helper that no longer exists, so it also checks that nothing still uses a removed route.

---

## Subtask T00a.1 — Remove the settings pages and the appearance preference

This is a removal, so it isn't test-first. Its test is the existing suite staying green without the settings tests, plus a build and type check that fail on any leftover import.

- [ ] **Step 1: Preflight: the removed components and composables are used only by files this subtask deletes, plus `app.ts`**

```bash
grep -rlE "components/(DeleteUser|ManagePasskeys|PasskeyItem|PasskeyRegister|ManageTwoFactor|TwoFactorSetupModal|TwoFactorRecoveryCodes|AppearanceTabs|Heading|AlertError)\.vue|composables/(useTwoFactorAuth|useAppearance)" resources/js --include=*.vue --include=*.ts | sort
```
Expected, exactly:
```
resources/js/app.ts
resources/js/components/AppearanceTabs.vue
resources/js/components/DeleteUser.vue
resources/js/components/ManagePasskeys.vue
resources/js/components/ManageTwoFactor.vue
resources/js/components/TwoFactorRecoveryCodes.vue
resources/js/components/TwoFactorSetupModal.vue
resources/js/layouts/settings/Layout.vue
resources/js/pages/settings/Appearance.vue
resources/js/pages/settings/Profile.vue
resources/js/pages/settings/Security.vue
```
**STOP and report** if any other file appears (a later change started using one of these).

```bash
grep -rn "appearance" app bootstrap routes tests resources/views
```
Expected: seven lines, all removed or rewritten by this subtask:
- `HandleAppearance.php:21`
- `bootstrap/app.php:36` (the `encryptCookies` exception)
- `tests/Feature/Auth/EmailVerificationTest.php:39` (`route('appearance.edit')`)
- `routes/settings.php:28`
- `resources/views/app.blade.php` lines 2, 10 and 12 (the `$appearance` class binding and the inline theme script)

- [ ] **Step 2: Delete the settings backend, pages, layout, components, composables, middleware and tests**

```bash
git rm -q -r app/Http/Controllers/Settings app/Http/Requests/Settings routes/settings.php \
  resources/js/pages/settings resources/js/layouts/settings tests/Feature/Settings \
  resources/js/components/{DeleteUser,ManagePasskeys,PasskeyItem,PasskeyRegister,ManageTwoFactor,TwoFactorSetupModal,TwoFactorRecoveryCodes,AppearanceTabs,Heading,AlertError}.vue \
  resources/js/composables/{useTwoFactorAuth,useAppearance}.ts \
  app/Http/Middleware/HandleAppearance.php
git status --short | wc -l
```
Expected: `26` (26 deleted files).

- [ ] **Step 3: Replace `routes/web.php`** (the settings `require` goes)

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
```

- [ ] **Step 4: Remove the appearance middleware and its cookie exception from `bootstrap/app.php`**

Three lines change; route loading and exception rendering stay exactly as T00 wrote them.

```bash
sed -i -e "/^use App\\\\Http\\\\Middleware\\\\HandleAppearance;$/d" \
       -e "/^            HandleAppearance::class,$/d" \
       -e "s/encryptCookies(except: \['appearance', 'sidebar_state'\])/encryptCookies(except: ['sidebar_state'])/" bootstrap/app.php
git diff --stat bootstrap/app.php && grep -n "Appearance\|encryptCookies" bootstrap/app.php
```
Expected: `1 file changed, 1 insertion(+), 3 deletions(-)`, and the grep prints only `$middleware->encryptCookies(except: ['sidebar_state']);`.

- [ ] **Step 5: Replace `resources/views/app.blade.php`**

The appearance cookie is gone, so the page follows the OS colour scheme at load. That's what every user who never opened the Appearance page already got (the kit's default was `system`). The kit's Blade comments are dropped (CLAUDE.md §Code style).

```blade
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <script>
            if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
                document.documentElement.classList.add('dark');
            }
        </script>

        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
```

- [ ] **Step 6: Replace `resources/js/app.ts`** (no settings layout, no `initializeTheme`; the kit's comments go)

The `Welcome` case stays for now, because `Welcome.vue` is deleted in T00a.2.

```ts
import { createInertiaApp } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { initializeFlashToast } from '@/lib/flashToast';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            default:
                return AppLayout;
        }
    },
    withApp: (app) => {
        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    progress: {
        color: '#4B5563',
    },
});

initializeFlashToast();
```

- [ ] **Step 7: Drop the appearance types from `resources/js/types/ui.ts`**

```bash
sed -i "/Appearance = /d" resources/js/types/ui.ts && sed -i '1{/^$/d}' resources/js/types/ui.ts && cat resources/js/types/ui.ts
```
Expected: the file starts with `export type AppVariant = 'header' | 'sidebar';` and still exports `FlashToast`.

- [ ] **Step 8: Replace `resources/js/components/UserMenuContent.vue`** (Settings link removed, logout kept)

```vue
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { LogOut } from '@lucide/vue';
import {
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import { logout } from '@/routes';
import type { User } from '@/types';

type Props = {
    user: User;
};

const handleLogout = () => {
    router.flushAll();
};

defineProps<Props>();
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true">
        <Link
            class="block w-full cursor-pointer"
            :href="logout()"
            @click="handleLogout"
            as="button"
            data-test="logout-button"
        >
            <LogOut class="mr-2 h-4 w-4" />
            Log out
        </Link>
    </DropdownMenuItem>
</template>
```

- [ ] **Step 9: Point the kit's one appearance-route assertion at `dashboard`**

`EmailVerificationTest` uses the Appearance page only as "some `verified` route". `dashboard` is still `verified` until T00a.2, which deletes this file.

```bash
sed -i "s/route('appearance.edit')/route('dashboard')/" tests/Feature/Auth/EmailVerificationTest.php && grep -n "route('dashboard')" tests/Feature/Auth/EmailVerificationTest.php
```
Expected: one line, `39:        $response = $this->actingAs($user)->get(route('dashboard'));`.

- [ ] **Step 10: Nothing references the removed code any more**

```bash
grep -rniE "appearance|settings\.php|profile\.(edit|update|destroy)|security\.edit" app bootstrap routes resources/views resources/js tests --include=*.php --include=*.ts --include=*.vue | grep -vE "resources/js/(routes|actions|wayfinder)/"
```
Expected: no output.

- [ ] **Step 11: Gate**

```bash
rm -rf resources/js/actions resources/js/routes resources/js/wayfinder
./vendor/bin/pint --test
./vendor/bin/phpstan analyse --memory-limit=1G
./vendor/bin/pest --parallel
npm run build && npm run check && npx vue-tsc --noEmit
```
Expected (measured on a copy of `main` at `c8c17a8`):
- `{"tool":"pint","result":"passed"}`
- `{"tool":"phpstan","result":"passed","errors":0}`
- `{"tool":"pest","result":"passed","tests":53,"passed":53,"assertions":183,...}`. That's 63 before, minus the 10 tests in `tests/Feature/Settings`.
- the build ends with `✓ built in …`
- `npm run check`: `pass: All 54 files are correctly formatted` and `pass: Found no warnings or lint errors in 48 files`
- `vue-tsc` exits 0 with no output

- [ ] **Step 12: Commit**

```bash
git add -A app bootstrap routes resources tests
git status --short
git commit -m "refactor(ui): remove the settings pages and the appearance preference" \
  -m "The owner dropped every settings page (profile, security, appearance); the PRD needs none of them. The appearance cookie and its middleware existed only for the Appearance page, so they go too, and the page now follows the OS colour scheme, which was already the kit's default. Fortify itself is removed in the next commit." \
  --trailer "Refs: T00a.1" --trailer "Implements: TD-002"
```
Expected: `git status --short` before the commit lists only `D`/`M` entries under the paths above, and nothing is left unstaged afterwards.

---

## Subtask T00a.2 — Replace Fortify with plain session authentication

- [ ] **Step 1: Preflight: what still depends on Fortify, passkeys and the OTP input**

```bash
grep -rlE "components/PasskeyVerify\.vue|components/ui/input-otp|vue-input-otp|@laravel/passkeys|TwoFactorConfigContent" resources/js --include=*.vue --include=*.ts | grep -vE "resources/js/(routes|actions|wayfinder)/" | sort
grep -rn "verified" routes app
```
Expected for the first command, exactly: `components/PasskeyVerify.vue`, `components/ui/input-otp/InputOTP.vue`, `components/ui/input-otp/InputOTPSlot.vue`, `pages/auth/ConfirmPassword.vue`, `pages/auth/Login.vue`, `pages/auth/TwoFactorChallenge.vue`, `types/auth.ts` (all under `resources/js/`). Expected for the second: `HandleInertiaRequests.php` (`email_verified_at`), `app/Models/User.php` (the `@property` line and the cast) and `routes/web/dashboard.php` (`['auth', 'verified']`). **STOP and report** on anything else.

- [ ] **Step 2: Delete the Fortify backend, then remove it from the provider list**

These must go **before** `composer remove`: `composer dump-autoload` runs `package:discover`, which boots the app, and `config/fortify.php` and the provider reference Fortify classes.

```bash
git rm -q -r app/Providers/FortifyServiceProvider.php config/fortify.php app/Actions/Fortify app/Concerns
```

Replace `bootstrap/providers.php`:

```php
<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
];
```

- [ ] **Step 3: Remove the Composer package**

`--no-scripts` matters. With the local Composer 2.7.4, the `pre-package-uninstall` hook loads the project's Symfony Console 7.4 into Composer's own process. `composer remove` then dies with `Fatal error: Declaration of Symfony\Component\Console\Input\ArrayInput::hasParameterOption(...) must be compatible with ...`, after the package files are already gone. The separate `dump-autoload` runs the post-autoload scripts cleanly.

```bash
composer remove laravel/fortify --no-scripts && composer dump-autoload
grep -c fortify composer.json composer.lock bootstrap/cache/packages.php
```
Expected:
- `Removing laravel/fortify (v1.41.0)`, plus the 14 packages only it needed: `bacon/bacon-qr-code`, `dasprid/enum`, `laravel/passkeys`, `paragonie/constant_time_encoding`, `pragmarx/google2fa`, `spomky-labs/cbor-php`, `spomky-labs/pki-framework`, `symfony/polyfill-php81`, `symfony/property-access`, `symfony/property-info`, `symfony/serializer`, `symfony/type-info`, `web-auth/cose-lib`, `web-auth/webauthn-lib`. No other package changes version.
- `dump-autoload` ends with `Generated optimized autoload files containing … classes`.
- all three counts are `0`.

- [ ] **Step 4: Remove the npm packages**

`vue-input-otp` was used only by `components/ui/input-otp` (deleted in Step 5) and `TwoFactorChallenge.vue`.

```bash
npm uninstall @laravel/passkeys vue-input-otp
git diff package.json
```
Expected: exactly two `dependencies` lines removed (`"@laravel/passkeys": "^0.2.0"`, `"vue-input-otp": "^0.3.2"`); `package-lock.json` also drops `@simplewebauthn/browser`.

- [ ] **Step 5: Delete the Fortify pages, components, migrations and tests**

```bash
git rm -q -r resources/js/pages/auth/{ConfirmPassword,ForgotPassword,ResetPassword,TwoFactorChallenge,VerifyEmail}.vue \
  resources/js/pages/Welcome.vue resources/js/components/PasskeyVerify.vue resources/js/components/ui/input-otp \
  database/migrations/2024_01_01_000000_create_passkeys_table.php \
  database/migrations/2025_08_14_170933_add_two_factor_columns_to_users_table.php \
  tests/Feature/Auth tests/Feature/DashboardTest.php
```
That deletes all 7 kit files in `tests/Feature/Auth`; this subtask writes the new Pest files there in Step 9.

- [ ] **Step 6: Replace the schema and the model**

`database/migrations/0001_01_01_000000_create_users_table.php` (one-off edit of a T00 migration, allowed because nothing has been deployed; every database is rebuilt with `migrate:fresh`; the kit's prose docblocks go):

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('sessions');
    }
};
```

`app/Models/User.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class User extends Authenticatable
{
    /** @use HasApiTokens<PersonalAccessToken|TransientToken> */
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }
}
```

`database/factories/UserFactory.php` (prose docblocks dropped; the type tags stay):

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }
}
```

`tests/TestCase.php` (its only method, `skipUnlessFortifyHas()`, imported `Laravel\Fortify\Features`):

```php
<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase {}
```

- [ ] **Step 7: Drop email verification everywhere else**

```bash
sed -i "/'email_verified_at' => \$user->email_verified_at?->toIso8601String(),/d" app/Http/Middleware/HandleInertiaRequests.php
sed -i "/->where('email_verified_at', \$user->email_verified_at?->toIso8601String())/d" tests/Feature/App/InertiaSharedPropsTest.php
sed -i "s/->middleware(\['auth', 'verified'\])/->middleware(['auth'])/" routes/web/dashboard.php
sed -i "/if (! \$user->hasVerifiedEmail()) {/,/^$/d" app/Actions/Auth/IssueApiToken.php
sed -i "/'unverified email' =>/d" tests/Feature/Api/V1/AuthTokenTest.php
sed -i "/case name === 'Welcome':/,+1d" resources/js/app.ts
git diff --stat
```
Expected `git diff --stat` lines for these six files: `HandleInertiaRequests.php | 1 -`, `InertiaSharedPropsTest.php | 1 -`, `dashboard.php | 2 +-`, `IssueApiToken.php | 4 ----`, `AuthTokenTest.php | 1 -`, `app.ts | 2 --`.

- `IssueApiToken` keeps its timebox, credentials check and abilities. Only the four-line `hasVerifiedEmail()` block goes. With no verification column, `hasVerifiedEmail()` would be false for every user and no token could ever be issued (Contract note 2).
- `AuthTokenTest`'s "refuses a token" dataset keeps `unknown email` and `wrong password`.

Replace `resources/js/types/auth.ts` (`Passkey` and `TwoFactorConfigContent` go with their only users; `avatar?` is the kit's, read by `AppHeader.vue`):

```ts
export type User = {
    id: number;
    name: string;
    email: string;
    roles: string[];
    avatar?: string;
};

export type AuthCan = {
    viewDashboard: boolean;
    manageImports: boolean;
    manageUsers: boolean;
    manageCategories: boolean;
    manageHolidays: boolean;
    createOnBehalf: boolean;
};

export type Auth = {
    user: User;
    can: AuthCan;
};
```

- [ ] **Step 8: Write `tests/Feature/Auth/LoginTest.php`** (acceptance criteria: login, bad credentials, throttle, signed-in users sent home, logout)

```php
<?php

declare(strict_types=1);

use App\Models\User;

it('logs a user in with valid credentials and redirects home', function (): void {
    $user = User::factory()->create();

    $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($user);
});

it('rejects bad credentials with an email error and keeps the visitor a guest', function (): void {
    $user = User::factory()->create();

    $this->from(route('login'))
        ->post(route('login'), ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => __('auth.failed')]);

    $this->assertGuest();
});

it('throttles login after five attempts per minute', function (): void {
    $payload = ['email' => 'nobody@example.com', 'password' => 'wrong-password'];

    foreach (range(1, 5) as $attempt) {
        $this->post(route('login'), $payload)->assertSessionHasErrors('email');
    }

    $this->post(route('login'), $payload)->assertTooManyRequests();
});

it('sends a signed-in user who opens the login page to home', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('login'))
        ->assertRedirect(route('home'));
});

it('logs the user out and redirects to the login page', function (): void {
    $this->actingAs(User::factory()->create())
        ->post(route('logout'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});
```

- [ ] **Step 9: Write `tests/Feature/Auth/RegisterTest.php`** (acceptance criteria: registration logs in and redirects home; duplicate email rejected)

`Hash::check` proves the stored password is the hashed form of the one submitted.

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('registers a new user, logs them in and redirects home', function (): void {
    $this->post(route('register'), [
        'name' => 'Jane Customer',
        'email' => 'jane@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('home'));

    $user = User::query()->where('email', 'jane@example.com')->sole();

    $this->assertAuthenticatedAs($user);
    expect(Hash::check('password', $user->password))->toBeTrue();
});

it('rejects an email that is already taken', function (): void {
    User::factory()->create(['email' => 'jane@example.com']);

    $this->from(route('register'))
        ->post(route('register'), [
            'name' => 'Jane Again',
            'email' => 'jane@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
        ->assertRedirect(route('register'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(User::query()->count())->toBe(1);
});
```

- [ ] **Step 10: Run them and confirm they fail**

```bash
./vendor/bin/pest tests/Feature/Auth
```
Expected: **FAIL**, 7 failures, all `Route [login] not defined.` or `Route [register] not defined.` (Fortify's routes are gone and the new ones don't exist yet).

- [ ] **Step 11: Create `app/Http/Requests/Auth/LoginRequest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['boolean'],
        ];
    }
}
```

- [ ] **Step 12: Create `app/Http/Requests/Auth/RegisterRequest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }
}
```
`Password::defaults()` with no argument returns the rule configured in T00's `AppServiceProvider::configureDefaults()`: 12+ mixed-case characters, symbols and uncompromised in production, and `min:8` elsewhere.

- [ ] **Step 13: Create `app/Actions/Auth/RegisterCustomer.php`**

```php
<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RegisterCustomer
{
    public function handle(string $name, string $email, string $password): User
    {
        return DB::transaction(fn (): User => User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]));
    }
}
```

- [ ] **Step 14: Create `app/Http/Controllers/Auth/LoginController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class LoginController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/Login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        if (! Auth::attempt($request->safe()->only(['email', 'password']), $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
```

- [ ] **Step 15: Create `app/Http/Controllers/Auth/RegisterController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\RegisterCustomer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class RegisterController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/Register');
    }

    public function store(RegisterRequest $request, RegisterCustomer $register): RedirectResponse
    {
        $user = $register->handle(
            $request->string('name')->toString(),
            $request->string('email')->toString(),
            $request->string('password')->toString(),
        );

        Auth::login($user);

        $request->session()->regenerate();

        return redirect()->route('home');
    }
}
```

- [ ] **Step 16: Create `routes/web/auth.php`**

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:5,1');

    Route::get('register', [RegisterController::class, 'create'])->name('register');
    Route::post('register', [RegisterController::class, 'store']);
});

Route::post('logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');
```

- [ ] **Step 16b: Send signed-in users who open a guest page to `home`**

The `guest` middleware otherwise redirects them to `/dashboard`, which customers can't open once T12 protects it. `home` routes every role to the right place (T00's `HomeController`). Add one line to `withMiddleware` in `bootstrap/app.php`, after the `encryptCookies` line:

```bash
sed -i "/^        \$middleware->encryptCookies(except: \['sidebar_state'\]);$/a\\        \$middleware->redirectUsersTo(fn (): string => route('home'));" bootstrap/app.php
grep -n "redirectUsersTo" bootstrap/app.php && git add bootstrap/app.php
```
Expected: one line, `$middleware->redirectUsersTo(fn (): string => route('home'));`. If the grep prints nothing, add that line by hand right after the `encryptCookies` call.

- [ ] **Step 17: Run the auth tests and confirm they pass**

```bash
./vendor/bin/pest tests/Feature/Auth
```
Expected: `{"tool":"pest","result":"passed","tests":7,"passed":7,...}`.

- [ ] **Step 18: Replace `resources/js/pages/auth/Login.vue`**

No passkey, status banner or forgot-password link, and no props. It posts through Wayfinder's `LoginController.store`, because the POST route is unnamed.

The `Remember me` checkbox gets `value="1"`. reka-ui's `CheckboxRoot` submits `on` by default (`node_modules/reka-ui/dist/Checkbox/CheckboxRoot.js`, `value` default `"on"`), and the `boolean` rule rejects `on`. So without `value="1"`, ticking the box would fail validation with "The remember field must be true or false."

```vue
<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { store } from '@/actions/App/Http/Controllers/Auth/LoginController';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { register } from '@/routes';

defineOptions({
    layout: {
        title: 'Log in to your account',
        description: 'Enter your email and password below to log in',
    },
});
</script>

<template>
    <Head title="Log in" />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    required
                    v-focus
                    :tabindex="1"
                    autocomplete="email"
                    placeholder="email@example.com"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="password">Password</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    :tabindex="2"
                    autocomplete="current-password"
                    placeholder="Password"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="flex items-center justify-between">
                <Label for="remember" class="flex items-center space-x-3">
                    <Checkbox
                        id="remember"
                        name="remember"
                        value="1"
                        :tabindex="3"
                    />
                    <span>Remember me</span>
                </Label>
            </div>

            <Button
                type="submit"
                class="mt-4 w-full"
                :tabindex="4"
                :disabled="processing"
                data-test="login-button"
            >
                <Spinner v-if="processing" />
                Log in
            </Button>
        </div>

        <div class="text-center text-sm text-muted-foreground">
            Don't have an account?
            <TextLink :href="register()" :tabindex="5">Sign up</TextLink>
        </div>
    </Form>
</template>
```

- [ ] **Step 19: Replace `resources/js/pages/auth/Register.vue`**

No `passwordRules` prop (the server's rule is the only one that counts). It posts through `RegisterController.store`.

```vue
<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { store } from '@/actions/App/Http/Controllers/Auth/RegisterController';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';

defineOptions({
    layout: {
        title: 'Create an account',
        description: 'Enter your details below to create your account',
    },
});
</script>

<template>
    <Head title="Register" />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    type="text"
                    required
                    v-focus
                    :tabindex="1"
                    autocomplete="name"
                    name="name"
                    placeholder="Full name"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    required
                    :tabindex="2"
                    autocomplete="email"
                    name="email"
                    placeholder="email@example.com"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="password">Password</Label>
                <PasswordInput
                    id="password"
                    required
                    :tabindex="3"
                    autocomplete="new-password"
                    name="password"
                    placeholder="Password"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Confirm password</Label>
                <PasswordInput
                    id="password_confirmation"
                    required
                    :tabindex="4"
                    autocomplete="new-password"
                    name="password_confirmation"
                    placeholder="Confirm password"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <Button
                type="submit"
                class="mt-2 w-full"
                tabindex="5"
                :disabled="processing"
                data-test="register-user-button"
            >
                <Spinner v-if="processing" />
                Create account
            </Button>
        </div>

        <div class="text-center text-sm text-muted-foreground">
            Already have an account?
            <TextLink
                :href="login()"
                class="underline underline-offset-4"
                :tabindex="6"
                >Log in</TextLink
            >
        </div>
    </Form>
</template>
```

- [ ] **Step 20: Routes and schema are what the contract says**

```bash
php artisan route:list --except-vendor
php artisan migrate:fresh --force
```
Expected `route:list`, exactly these 10 routes:
```
GET|HEAD  /                          home › HomeController
POST      api/v1/auth/tokens         api.v1.auth.tokens.store › Api\V1\AuthTokenController@store
DELETE    api/v1/auth/tokens/current api.v1.auth.tokens.destroy › Api\V1\AuthTokenController@destroy
GET|HEAD  api/v1/me                  api.v1.auth.me › Api\V1\CurrentUserController
GET|HEAD  dashboard                  dashboard › routes/web/dashboard.php:9
GET|HEAD  login                      login › Auth\LoginController@create
POST      login                      Auth\LoginController@store
POST      logout                     logout › Auth\LoginController@destroy
GET|HEAD  register                   register › Auth\RegisterController@create
POST      register                   Auth\RegisterController@store
```
No `settings/*`, `two-factor/*`, `user/*`, `forgot-password`, `reset-password`, `email/verify*`, `passkeys/*` or `.well-known/passkey-endpoints` routes.

`migrate:fresh` (worktree dev database `techzu_wt_t00a`) runs exactly 5 migrations: `create_users_table`, `create_cache_table`, `create_jobs_table`, `create_permission_tables` and `create_personal_access_tokens_table`.

- [ ] **Step 21: Nothing references Fortify, passkeys, two-factor, verification or the removed pages**

```bash
git add -A app bootstrap config database resources routes tests composer.json composer.lock package.json package-lock.json
git grep -niE "fortify|passkey|two.?factor|verified|password_reset|input-otp|appearance|settings\.php|Welcome" -- app bootstrap config routes resources tests database composer.json package.json
```
Expected, exactly two lines, both kit config left as it is:
- `config/auth.php:100` (the unused `password_reset_tokens` broker table; see **Kept unchanged**)
- `config/session.php:87` (the word "welcome" in a kit comment)

- [ ] **Step 22: Gate**

```bash
rm -rf resources/js/actions resources/js/routes resources/js/wayfinder
./vendor/bin/pint --test
./vendor/bin/phpstan analyse --memory-limit=1G
./vendor/bin/pest --parallel
npm run build && npm run check && npx vue-tsc --noEmit
```
Expected (measured on a copy of `main` at `c8c17a8` with T00a.1 applied):
- `{"tool":"pint","result":"passed"}`
- `{"tool":"phpstan","result":"passed","errors":0}`
- `{"tool":"pest","result":"passed","tests":31,"passed":31,...}`. From 53: minus 26 kit tests in `tests/Feature/Auth`, minus 2 in `DashboardTest`, minus 1 `AuthTokenTest` dataset row, plus the 7 new tests. The kit tests removed are `AuthenticationTest` 6, `EmailVerificationTest` 7, `PasswordConfirmationTest` 2, `PasswordResetTest` 5, `RegistrationTest` 2, `TwoFactorChallengeTest` 2 and `VerificationNotificationTest` 2.
- the build ends with `✓ built in …`
- `npm run check`: `pass: All 47 files are correctly formatted` and `pass: Found no warnings or lint errors in 41 files`
- `vue-tsc` exits 0

- [ ] **Step 23: Commit**

```bash
git status --short
git commit -m "refactor(auth): replace fortify with plain session authentication" \
  -m "The owner removed Fortify: the PRD needs session login, logout and customer self-registration, not two-factor auth, passkeys, email verification or password reset. Login and registration are now two thin controllers, one FormRequest each and a RegisterCustomer action; Sanctum tokens are unchanged apart from dropping the email-verified check, which can no longer pass. The users migration is edited in place (nothing is deployed); rebuild databases with migrate:fresh." \
  --trailer "Refs: T00a.2" --trailer "Implements: TD-001, TD-002, TD-120"
```
Expected: `git status --short` shows everything staged, with nothing under `??` or with an unstaged second column, and the commit leaves a clean tree.

---

## Browser check (controller, README §2 step 6)

On the worktree, run `php artisan migrate:fresh --seed && npm run build && php artisan serve --port=8100`, then use Playwright MCP against `http://127.0.0.1:8100`:
1. `/` redirects to `/login`. The page shows email, password, "Remember me" and "Sign up", with no passkey button and no "Forgot your password?".
2. Log in as `test@example.com` / `password` **with "Remember me" ticked**. You land on `/dashboard` with no validation error, which proves the `value="1"` fix.
3. Open the user menu. It has "Log out" and no "Settings". Log out and you land on `/login`.
4. Register a new account. You land on `/dashboard`, signed in. Registering the same email again shows "The email has already been taken."
5. `/settings/profile` and `/two-factor-challenge` return 404. The console has no errors.

Stop the server afterwards and confirm with `pgrep -af "artisan serve"`.

---

## Acceptance criteria

- [ ] `laravel/fortify` is not in `composer.json`/`composer.lock`, and `@laravel/passkeys` and `vue-input-otp` are not in `package.json`/`package-lock.json`. No other dependency changed version.
- [ ] `bootstrap/providers.php` lists only `AppServiceProvider`. `config/fortify.php`, `app/Actions/Fortify/` and `app/Concerns/` don't exist.
- [ ] No settings routes, controllers, requests, pages, layout or tests exist. The user menu has no Settings link and keeps Log out.
- [ ] `HandleAppearance`, the `appearance` cookie exception, `useAppearance`, `AppearanceTabs` and the `Appearance` types are gone. The page follows the OS colour scheme.
- [ ] The users migration has no `email_verified_at` column and no `password_reset_tokens` table. The passkeys and two-factor migrations are deleted, and `migrate:fresh` runs 5 migrations.
- [ ] `User` has no Fortify contract or trait and keeps `HasApiTokens<PersonalAccessToken|TransientToken>`, `HasFactory`, `HasRoles`, `Notifiable`, `$fillable` (`name`, `email`, `password`), `$hidden` (`password`, `remember_token`) and the `hashed` cast. `UserFactory` has no two-factor columns, `email_verified_at` or extra states.
- [ ] `routes/web/auth.php` defines exactly the five routes in **Interfaces**, with those names and middleware. No route uses `verified`.
- [ ] Valid credentials log in and redirect to `home`. Bad credentials redirect back with an `email` error (`auth.failed`) and leave the visitor a guest. The 6th login attempt within a minute gets 429. Logout ends the session and redirects to `login`.
- [ ] Registration creates the user (password hashed), logs them in and redirects to `home`. A taken email is rejected with an `email` error. No role is assigned (T01 adds it).
- [ ] `auth.user` is exactly `{id, name, email, roles}` in `HandleInertiaRequests`, `InertiaSharedPropsTest` and `resources/js/types/auth.ts`.
- [ ] `POST api/v1/auth/tokens`, `DELETE api/v1/auth/tokens/current` and `GET api/v1/me` behave as in T00. The only difference is that no email-verified check exists.
- [ ] `Login.vue` and `Register.vue` have no passkey, two-factor, forgot-password or password-rules parts and post to `/login` and `/register`. "Remember me" submits `1`.
- [ ] No code file has a comment (CLAUDE.md §Code style). Rewritten kit files (`app.blade.php`, `app.ts`, `UserFactory.php`, the users migration) lost their prose comments.
- [ ] Two commits on the task branch, `Refs: T00a.1` and `Refs: T00a.2`, each passing the gate alone.

## Verification

Each commit must pass the whole gate on its own (README §2 step 9). For T00a the plain `--exec` gate from README §2 isn't enough, for two reasons:
- The two commits have different `composer.lock` and `package-lock.json` files.
- The PHP tests render Inertia pages through `@vite`, so they need a Vite manifest built from that commit. Run at T00a.1 with the tip's build, `EmailVerificationTest` gets `ViteException: Unable to locate file in Vite manifest: resources/js/pages/auth/VerifyEmail.vue` (500).

So every commit reinstalls its dependencies and rebuilds first:

```bash
git rebase main --exec "composer install --no-scripts --no-interaction --quiet && composer dump-autoload --quiet && npm ci --silent && rm -rf resources/js/actions resources/js/routes resources/js/wayfinder && npm run build --silent >/dev/null && ./vendor/bin/pint --test && ./vendor/bin/phpstan analyse --memory-limit=1G && DB_DATABASE=techzu_wt_t00a_testing ./vendor/bin/pest --parallel && npm run check --silent && npx vue-tsc --noEmit"
php artisan route:list --except-vendor
```
On an up-to-date branch, `git rebase main` has nothing to replay and skips `--exec`. In that case use `git rebase -f main --exec "…"`, which is what the prototype ran.

Expected:
- After T00a.1: pint and phpstan pass, then `{"tool":"pest","result":"passed","tests":53,"passed":53,"assertions":183,...}`, then `pass: All 54 files are correctly formatted` and `pass: Found no warnings or lint errors in 48 files`.
- After T00a.2: pint and phpstan pass, then `{"tool":"pest","result":"passed","tests":31,"passed":31,...}`, then `pass: All 47 files are correctly formatted` and `pass: Found no warnings or lint errors in 41 files`.
- `Successfully rebased and updated refs/heads/task/T00a-simple-auth`.
- `route:list` shows the 10 routes from T00a.2 Step 20.

After the owner lands T00a, rebuild the main checkout's database once: `php artisan migrate:fresh --seed` on `techzu`. Its `migrations` table still lists the deleted passkeys and two-factor migrations, and `users` still has the dropped columns.

---

## Contract notes for controller

1. **Appearance removed, not kept.** It was dead code once the Appearance page went, and removing it touched three deletions (`HandleAppearance.php`, `useAppearance.ts`, `AppearanceTabs.vue`) and four small edits (`bootstrap/app.php`, `app.ts`, which T00a.1 edits anyway, `app.blade.php` and `types/ui.ts`). That is within "a handful". The kit's default was `system`, so behaviour is unchanged for anyone who never chose a theme. `bootstrap/app.php` is "no later modifications" in README §4; T00a edits it once, for these three lines only, and §4 needs a T00a entry for it.
2. **Deviation: `IssueApiToken` loses its email-verified check.** The contract says the token API is unchanged. But `Illuminate\Foundation\Auth\User` still uses the `MustVerifyEmail` *trait*, so `hasVerifiedEmail()` exists and reads `email_verified_at`. Once that column is gone it is `false` for every user, and no token could ever be issued. The 4-line check and the matching `'unverified email'` row in `AuthTokenTest` are removed; everything else (timebox, 422 on bad credentials, abilities, throttle) is untouched. README §4 should list `app/Actions/Auth/IssueApiToken.php` and `tests/Feature/Api/V1/AuthTokenTest.php` as modified by T00a.
3. **`tests/TestCase.php` is modified** (not in the contract's list). Its only method, `skipUnlessFortifyHas()`, imports `Laravel\Fortify\Features`, so it can't survive the package removal. It becomes an empty abstract class. T04's "must not touch" for this file still holds after T00a.
4. **Unnamed POST routes.** As the contract has it, `POST login` and `POST register` have no names, so the Vue pages use Wayfinder's controller helpers (`@/actions/App/Http/Controllers/Auth/*Controller` → `store.form()`), not `@/routes/login`. Authorization-matrix tests (T04/T14) should address them by URI (`/login`, `/register`).
5. **Resolved: signed-in users who open `/login` or `/register` go to `home`** (T00a.2 Step 16b, `redirectUsersTo`), not to Laravel's default `/dashboard`. Customers would otherwise hit T12's 403 there. `LoginTest` proves it.
6. **Composer 2.7.4 and `composer remove`.** Plain `composer remove laravel/fortify` crashes in the `pre-package-uninstall` hook. Composer's bundled Symfony Console clashes with the project's 7.4, giving `ArrayInput::hasParameterOption … must be compatible`. That leaves the lock updated but the autoloader stale. `--no-scripts` followed by `composer dump-autoload` is clean, which is why Step 3 uses it. Upgrading Composer (`composer self-update`, owner's call) would also fix it. `composer dump-autoload` and `composer install --no-scripts` are not affected: the per-commit gate in **Verification** reinstalls Fortify for T00a.1 and removes it again for T00a.2 without the crash.
7. **`docs/FEATURES.md` has no T00a section.** README §2 step 7 has the documenter replace "its own `_Pending._` section", and the file has none for T00a. The controller should add `## Authentication (T00a)` with `_Pending._` after "Platform foundation (T00)" before T00a starts. The documenter commit is then `docs(auth): document authentication logic` with `Refs: T00a`.
8. **`APP_KEY`.** `.env.example` (and, at the time of writing, the main checkout's `.env`) has a blank `APP_KEY`, and `phpunit.xml` sets none, so every HTTP test fails with `No application encryption key has been specified.` until `php artisan key:generate` runs. README §2 step 1 should say so for every worktree.
9. **Components deleted beyond the two-factor/passkey/delete-user ones.** `Heading.vue` and `AlertError.vue` were used only by the removed settings pages and two-factor components. No later plan references them, so they go under the contract's "used only by removed pages" rule. Later pages that want a section heading write their own. The shadcn primitives under `components/ui/` stay (only `input-otp` goes, with its npm package).
10. **Prototype.** Every step above was run on a copy of `main` at `c8c17a8`, against the existing MySQL server with throwaway databases. Both commits passed the full gate when committed, and again under the per-commit `rebase --exec` in **Verification**, with the counts recorded there.
11. **README §2 step 9 applies to every task.** The `--exec` gate reuses the tip's `vendor/`, `node_modules/` and `public/build`. Any task whose commits add or remove an Inertia page, or change a lockfile, will fail intermediate commits for the wrong reason (a stale Vite manifest or missing packages). The fuller exec line above is the general fix. A temporary `php artisan serve` confirmed `/` → 302 `/login`, `/login` 200, `/register` 200, `/dashboard` → 302 `/login` for a guest, and `/settings/profile` 404.
