<?php

namespace App\Console\Commands;

use App\Auth\IssueInvitation;
use Illuminate\Console\Command;
use InvalidArgumentException;

class UsersInviteCommand extends Command
{
    protected $signature = 'users:invite {email} {role : owner, reviewer or member} {--json : machine output on stdout}';

    protected $description = 'Invite a new user, or re-invite an existing one; prints the single-use set-password link once';

    public function handle(IssueInvitation $issue): int
    {
        try {
            $invitation = $issue($this->argument('email'), $this->argument('role'));
        } catch (InvalidArgumentException $e) {
            $this->getOutput()->getErrorStyle()->writeln('<error>'.$e->getMessage().' Check the role (owner, reviewer, member) and the email.</error>');

            return self::FAILURE;
        }

        // The link is a credential: it goes to stdout only, once, and is never logged.
        if ($this->option('json')) {
            $this->line(json_encode(['email' => $invitation->email, 'role' => $invitation->role, 'expires_at' => $invitation->expires_at->toIso8601String(), 'link' => $issue->link], JSON_UNESCAPED_SLASHES));
        } else {
            $this->getOutput()->getErrorStyle()->writeln("Link for {$invitation->email} as {$invitation->role}; one use, expires {$invitation->expires_at}:");
            $this->line($issue->link);
        }
        if ($issue->mailError) {
            $this->getOutput()->getErrorStyle()->writeln("<comment>The mail could not be sent ({$issue->mailError}); the link above is still valid.</comment>");
        }

        return self::SUCCESS;
    }
}
