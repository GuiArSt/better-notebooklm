<?php

namespace App\Http\Middleware;

use App\Auth\RevokePro;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * On every web request of a signed-in user: a disabled account is signed out, and a lapsed pro period is revoked, so code that
 * reads users.tier never sees a stale tier.
 */
class ResolveAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->isDisabled()) {
            // A disabled account is signed out on its next request, remember-me cookie included.
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'This account is disabled.']);
        }

        // Keep the stored tier true: a lapsed purchase is revoked in the column, whatever the role (a role is pro without it).
        if ($user?->tier === 'pro' && ! $user->hasProPurchase()) {
            app(RevokePro::class)($user);
        }

        return $next($request);
    }
}
