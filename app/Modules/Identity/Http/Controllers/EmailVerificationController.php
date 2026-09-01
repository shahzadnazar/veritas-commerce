<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Email verification.
 *
 * The link is a signed URL scoped to the user's id and a hash of their
 * current address, so it stops working the moment the address changes.
 */
final class EmailVerificationController
{
    public function notice(Request $request): RedirectResponse|Response
    {
        $user = $request->user('web');

        if ($user instanceof User && $user->hasVerifiedEmail()) {
            return redirect()->route('home');
        }

        return Inertia::render('Auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]);
    }

    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        // Named guard, as everywhere: the default shifts with context, and
        // only a customer verifies an address through this route.
        $user = $request->user('web');

        abort_if(! $user instanceof User, 403);

        if ($user->hasVerifiedEmail()) {
            return redirect()->route('home');
        }

        if ($user->markEmailAsVerified()) {
            Event::dispatch(new Verified($user));
        }

        return redirect()->route('home')->with('status', 'Your email address is verified.');
    }

    public function send(Request $request): RedirectResponse
    {
        $user = $request->user('web');

        if ($user instanceof User && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return back()->with('status', 'A fresh verification link is on its way.');
    }
}
