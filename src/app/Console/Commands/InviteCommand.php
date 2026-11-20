<?php

namespace App\Console\Commands;

use App\Auth\IssueInvitation;
use Illuminate\Console\Command;
use InvalidArgumentException;

class InviteCommand extends Command
{
    protected $signature = 'invite {email : Address to invite} {role : owner, reviewer or member}';

    protected $description = 'Invite someone: mails a single-use set-password link (72 hours)';

    public function handle(IssueInvitation $issue): int
    {
        try {
            $invitation = $issue($this->argument('email'), $this->argument('role'));
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Invitation for {$invitation->email} as {$invitation->role}; expires {$invitation->expires_at}. One use only. Send them this link:");
        // The link is the only secret printed; with a real mailer it was also mailed.
        $this->line($issue->link);

        if ($issue->mailError) {
            // Not a failure: the link above is valid and is what the owner hands over.
            $this->getOutput()->getErrorStyle()->writeln("<comment>The mail could not be sent ({$issue->mailError}); the link above is still valid.</comment>");
        }

        return self::SUCCESS;
    }
}
