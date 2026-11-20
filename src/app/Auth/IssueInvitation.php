<?php

namespace App\Auth;

use App\Ai\SafeLog;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Creates an invitation (also for an address that already has an account: the link then sets a new password, which is how
 * a locked-out user gets back in, D-022) and mails its single-use, signed set-password link (72 hours). */
class IssueInvitation
{
    public const HOURS = 72;

    /** The set-password link of the last invitation issued by this instance (the invite command prints it). */
    public ?string $link = null;

    /** Why the mail could not be sent, if it could not: the link is still valid (the owner hands it over, D-022). */
    public ?string $mailError = null;

    public function __invoke(string $email, string $role, ?User $inviter = null): Invitation
    {
        $email = Str::lower(trim($email));

        if (! in_array($role, Role::ALL, true)) {
            throw new InvalidArgumentException("Unknown role [{$role}].");
        }
        if (User::where('email', $email)->whereNotNull('disabled_at')->exists()) {
            throw new InvalidArgumentException("{$email} is disabled. Run users:disable {$email} --undo first, then send a link.");
        }

        // Re-inviting replaces the old link.
        Invitation::where('email', $email)->pending()->update(['revoked_at' => now()]);

        $token = Str::random(64);
        $invitation = Invitation::create([
            'email' => $email,
            'role' => $role,
            'token_hash' => hash('sha256', $token),
            'invited_by' => $inviter?->id,
            'expires_at' => Carbon::now()->addHours(self::HOURS),
        ]);

        $this->link = $this->link($invitation, $token);
        // With the log mailer nothing real is sent, so the mail (which holds the link) is not written to the logs either.
        if (config('mail.default') !== 'log') {
            try {
                Mail::to($email)->send(new InvitationMail($invitation, $this->link, $inviter));
            } catch (Throwable $e) {
                $this->mailError = $e::class;
                SafeLog::failure('Invitation mail', $e, ['invitation_id' => $invitation->id]);
            }
        }

        return $invitation;
    }

    private function link(Invitation $invitation, string $token): string
    {
        // Built from APP_URL, never from the request's Host header.
        URL::forceRootUrl(config('app.url'));
        URL::forceScheme(parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'http');
        try {
            return URL::temporarySignedRoute('invitation.show', $invitation->expires_at, [
                'invitation' => $invitation->id,
                'token' => $token,
            ]);
        } finally {
            URL::forceRootUrl(null);
            URL::forceScheme(null);
        }
    }
}
