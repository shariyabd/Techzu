<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Auth;

use App\Enums\Role;
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
        return Inertia::render('auth/Login', [
            'demoAccounts' => $this->demoAccounts(),
        ]);
    }

    /** @return list<array{role: string, label: string, email: string, password: string}> */
    private function demoAccounts(): array
    {
        if (! config()->boolean('demo.login_accounts.enabled')) {
            return [];
        }

        $password = config()->string('demo.login_accounts.password');
        $accounts = [];

        foreach (config()->array('demo.login_accounts.emails') as $role => $email) {
            $accounts[] = [
                'role' => (string) $role,
                'label' => Role::from((string) $role)->label(),
                'email' => (string) $email,
                'password' => $password,
            ];
        }

        return $accounts;
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        if (! Auth::attempt($request->safe()->only(['email', 'password']), $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $request->session()->regenerate();

        Inertia::clearHistory();

        return redirect()->intended(route('home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Inertia::clearHistory();

        return redirect()->route('login');
    }
}
