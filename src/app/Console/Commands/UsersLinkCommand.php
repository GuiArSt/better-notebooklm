<?php

namespace App\Console\Commands;

use App\Auth\IssueInvitation;
use App\Models\User;
use Illuminate\Console\Command;
use InvalidArgumentException;

class UsersLinkCommand extends Command
{
    protected $signature = 'users:link {email} {--json : machine output on stdout}';

    protected $description = 'A fresh set-password link for an existing user (they choose the password; none is taken or printed)';

    public function handle(IssueInvitation $issue): int
    {
        $user = User::where('email', mb_strtolower(trim($this->argument('email'))))->first();
        if (! $user) {
            $this->getOutput()->getErrorStyle()->writeln("<error>No user {$this->argument('email')}. Check users:list, or add them with users:invite.</error>");

            return self::FAILURE;
        }

        try {
            $invitation = $issue($user->email, $user->role);
        } catch (InvalidArgumentException $e) {
            $this->getOutput()->getErrorStyle()->writeln('<error>'.$e->getMessage().'</error>');

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode(['email' => $user->email, 'expires_at' => $invitation->expires_at->toIso8601String(), 'link' => $issue->link], JSON_UNESCAPED_SLASHES));
        } else {
            $this->getOutput()->getErrorStyle()->writeln("Link for {$user->email}; one use, expires {$invitation->expires_at}:");
            $this->line($issue->link);
        }

        return self::SUCCESS;
    }
}
