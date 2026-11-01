<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Auth;

use App\Actions\Auth\RegisterCustomer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Services\Auth\VerificationMailNotice;
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

    public function store(RegisterRequest $request, RegisterCustomer $register, VerificationMailNotice $notice): RedirectResponse
    {
        $user = $register->handle(
            $request->string('name')->toString(),
            $request->string('email')->toString(),
            $request->string('password')->toString(),
        );

        Auth::login($user);

        $request->session()->regenerate();

        Inertia::clearHistory();

        return redirect()->route('verification.notice')->with('success', $notice->linkSent($user->email));
    }
}
