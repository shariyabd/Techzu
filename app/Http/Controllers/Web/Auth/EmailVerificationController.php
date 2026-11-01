<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Auth;

use App\Actions\Auth\ResendEmailVerification;
use App\Actions\Auth\VerifyEmailAddress;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\VerificationMailNotice;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class EmailVerificationController extends Controller
{
    public function notice(Request $request, VerificationMailNotice $notice): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return redirect()->route('home');
        }

        return Inertia::render('auth/VerifyEmail', [
            'email' => $user->email,
            'mailIsLogged' => $notice->mailIsLogged(),
        ]);
    }

    public function verify(EmailVerificationRequest $request, VerifyEmailAddress $verify): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $verify->handle($user);

        return redirect()->route('home')->with('success', 'Your email address is verified. Welcome aboard!');
    }

    public function send(Request $request, ResendEmailVerification $resend, VerificationMailNotice $notice): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return redirect()->route('home');
        }

        $resend->handle($user);

        return back()->with('success', $notice->linkSent($user->email));
    }
}
