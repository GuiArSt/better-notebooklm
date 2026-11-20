<?php

namespace App\Http\Controllers\Settings;

use App\Auth\DeleteAccount;
use App\Auth\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'hasPassword' => $request->user()->password !== null,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated())->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request, DeleteAccount $delete): RedirectResponse
    {
        $user = $request->user();

        // The only owner cannot remove themselves: nobody could administer the app afterwards.
        if ($user->role === Role::OWNER && ! User::where('role', Role::OWNER)->whereKeyNot($user->id)->exists()) {
            return back()->withErrors([$user->password === null ? 'email' : 'password' => 'You are the only owner. Make someone else owner first, then delete your account.']);
        }

        Auth::logout();

        $delete($user);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // The browser clears its own chat history when it sees this flash (P-3).
        return redirect('/')->with('account_deleted', true);
    }
}
