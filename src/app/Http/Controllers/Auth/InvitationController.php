<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/** The set-password page behind an invitation link: signed, expiring, single-use. */
class InvitationController extends Controller
{
    public function show(Request $request, Invitation $invitation): Response|HttpResponse
    {
        if (! $this->usable($request, $invitation)) {
            return $this->invalid($request);
        }

        return Inertia::render('auth/accept-invitation', [
            'email' => $invitation->email,
            'action' => $request->fullUrl(),
        ]);
    }

    public function store(Request $request, Invitation $invitation): RedirectResponse|HttpResponse
    {
        if (! $this->usable($request, $invitation)) {
            return $this->invalid($request);
        }

        $existing = User::where('email', $invitation->email)->exists();
        $data = $request->validate([
            'name' => [$existing ? 'nullable' : 'required', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', $this->passwordRule()],
        ]);

        $user = DB::transaction(function () use ($invitation, $data) {
            // Lock the row so two clicks cannot both consume the link.
            $fresh = Invitation::whereKey($invitation->id)->lockForUpdate()->first();
            if (! $fresh->isPending()) {
                return null;
            }

            $user = User::where('email', $fresh->email)->first();
            if ($user) {
                // A re-invitation of an existing account: a new password and the invited role; every old session ends.
                $user->forceFill([
                    'password' => $data['password'], 'role' => $fresh->role, 'email_verified_at' => $user->email_verified_at ?? now(),
                    'remember_token' => Str::random(60), // an old remember-me cookie stops working, as after a password reset
                ])->save();
                DB::table('sessions')->where('user_id', $user->id)->delete();
            } else {
                $user = new User(['name' => $data['name'], 'email' => $fresh->email, 'password' => $data['password']]);
                $user->forceFill(['role' => $fresh->role, 'email_verified_at' => now()])->save();
            }
            $fresh->forceFill(['accepted_at' => now()])->save();

            return $user;
        });

        if (! $user) {
            return $this->invalid($request);
        }

        // An account with two-factor must still pass it: the new password alone does not sign it in.
        if ($user->two_factor_confirmed_at !== null) {
            return redirect()->route('login')->with('status', 'Password set. Sign in with it.');
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function invalid(Request $request): HttpResponse
    {
        return Inertia::render('auth/invitation-invalid')->toResponse($request)->setStatusCode(410);
    }

    private function usable(Request $request, Invitation $invitation): bool
    {
        return $invitation->isPending()
            && $invitation->tokenMatches((string) $request->query('token'));
    }

    /** 12 characters minimum, 128 maximum, no composition rules (OWASP); breach check in production. */
    private function passwordRule(): Password
    {
        $rule = Password::min(12)->max(128);

        return app()->isProduction() ? $rule->uncompromised() : $rule;
    }
}
