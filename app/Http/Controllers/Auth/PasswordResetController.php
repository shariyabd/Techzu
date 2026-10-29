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
