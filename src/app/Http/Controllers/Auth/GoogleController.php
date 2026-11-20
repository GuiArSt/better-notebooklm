<?php

namespace App\Http\Controllers\Auth;

use App\Ai\SafeLog;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/** Google sign-in. Scopes: openid, email, profile. Never creates an account without an invitation. */
class GoogleController extends Controller
{
    public function redirect(): RedirectResponse
    {
        // prompt=select_account: Google always shows its account chooser, so someone with several accounts can pick the invited one.
        return Socialite::driver('google')->scopes(['openid', 'email', 'profile'])->with(['prompt' => 'select_account'])->redirect();
    }

    public function callback(): RedirectResponse
    {
        try {
            $google = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            SafeLog::failure('Google callback', $e);

            return $this->refuse('Google sign-in failed. Please try again.');
        }

        $email = Str::lower((string) $google->getEmail());
        $verified = filter_var($google->user['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($email === '' || ! $verified) {
            return $this->refuse('Google did not confirm that email address.');
        }

        $user = User::where('google_id', $google->getId())->first()
            ?? $this->linkOrCreate($email, $google->getId(), (string) ($google->getName() ?: $email));

        if (! $user) {
            return $this->refuse('This address has not been invited.');
        }

        if ($user->isDisabled()) {
            return $this->refuse('This account is disabled.');
        }

        Auth::login($user);
        request()->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function linkOrCreate(string $email, string $googleId, string $name): ?User
    {
        return DB::transaction(function () use ($email, $googleId, $name) {
            if ($user = User::where('email', $email)->first()) {
                $user->forceFill(['google_id' => $googleId, 'email_verified_at' => $user->email_verified_at ?? now()])->save();

                return $user;
            }

            $invitation = Invitation::where('email', $email)->pending()->lockForUpdate()->latest('id')->first();
            if (! $invitation) {
                return null;
            }

            $user = new User(['name' => $name, 'email' => $email]);
            $user->forceFill(['role' => $invitation->role, 'google_id' => $googleId, 'email_verified_at' => now()])->save();
            $invitation->forceFill(['accepted_at' => now()])->save();

            return $user;
        });
    }

    private function refuse(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
