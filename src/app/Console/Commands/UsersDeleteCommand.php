<?php

namespace App\Console\Commands;

use App\Auth\DeleteAccount;
use App\Auth\Role;
use App\Models\User;
use Illuminate\Console\Command;

class UsersDeleteCommand extends Command
{
    protected $signature = 'users:delete {email} {--confirm= : the email again, to delete without a prompt}';

    protected $description = 'Delete a user and all their data for good (GDPR); the user must be disabled first (users:disable keeps the data), and you type the email back. Never the only owner.';

    public function handle(DeleteAccount $delete): int
    {
        $err = $this->getOutput()->getErrorStyle();
        $email = mb_strtolower(trim($this->argument('email')));
        $user = User::where('email', $email)->first();
        if (! $user) {
            $this->components->info("No user {$email}: already deleted, or never existed.");

            return self::SUCCESS;
        }

        // "Nothing durable is deleted" by accident: disable first (it blocks sign-in and keeps the data), then delete.
        if (! $user->isDisabled()) {
            $err->writeln("<error>{$user->email} is still active. Run users:disable {$user->email} first, then delete.</error>");

            return self::FAILURE;
        }
        if ($user->role === Role::OWNER && ! User::where('role', Role::OWNER)->whereKeyNot($user->id)->exists()) {
            $err->writeln("<error>{$user->email} is the only owner. Make someone else owner first (users:role), then delete.</error>");

            return self::FAILURE;
        }

        $given = $this->option('confirm') ?? ($this->input->isInteractive() ? $this->ask("This deletes {$user->email} ({$user->role}) and all their data for good. Type the email to confirm") : null);
        if (mb_strtolower(trim((string) $given)) !== $user->email) {
            $err->writeln('<error>Not confirmed, nothing deleted. Type the email back (--confirm=<email>), or use users:disable to keep the data.</error>');

            return self::FAILURE;
        }

        $delete($user);
        $this->components->info("{$user->email} deleted.");

        return self::SUCCESS;
    }
}
