<?php

namespace App\Http\Controllers\Admin;

use App\Auth\IssueInvitation;
use App\Auth\Role;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/** Owner-only: who has an account, who is invited, and a form to invite. */
class UserAdminController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/users', [
            'users' => User::orderBy('id')->get(['id', 'name', 'email', 'role', 'tier', 'pro_until', 'created_at']),
            'invitations' => Invitation::latest('id')->get(['id', 'email', 'role', 'expires_at', 'accepted_at', 'revoked_at'])
                ->map(fn (Invitation $i) => [
                    'id' => $i->id, 'email' => $i->email, 'role' => $i->role, 'expires_at' => $i->expires_at,
                    'status' => $i->accepted_at ? 'accepted' : ($i->revoked_at ? 'revoked' : ($i->expires_at->isPast() ? 'expired' : 'pending')),
                ]),
            'roles' => Role::ALL,
        ]);
    }

    public function invite(Request $request, IssueInvitation $issue): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(Role::ALL)],
        ]);

        try {
            $issue($data['email'], $data['role'], $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['email' => $e->getMessage()]);
        }

        // With no mail service (D-022), or when the mail fails, the owner hands the link over himself.
        return back()->with('status', config('mail.default') === 'log' || $issue->mailError
            ? "Invitation for {$data['email']}. Send them this link: {$issue->link}"
            : "Invitation sent to {$data['email']}.");
    }
}
