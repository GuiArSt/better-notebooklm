<?php

namespace App\Console\Commands;

use App\Auth\Role;
use App\Models\User;
use Illuminate\Console\Command;

class UsersRoleCommand extends Command
{
    protected $signature = 'users:role {email} {role : owner, reviewer or member} {--confirm= : the email again, to change owner status without a prompt}';

    protected $description = 'Change a user\'s role; making someone owner, or taking owner away, asks you to type the email back';

    public function handle(): int
    {
        $err = $this->getOutput()->getErrorStyle();
        $role = $this->argument('role');
        if (! in_array($role, Role::ALL, true)) {
            $err->writeln('<error>Role must be owner, reviewer or member. Try again with one of those.</error>');

            return self::FAILURE;
        }

        $user = User::where('email', mb_strtolower(trim($this->argument('email'))))->first();
        if (! $user) {
            $err->writeln("<error>No user {$this->argument('email')}. Check users:list.</error>");

            return self::FAILURE;
        }

        if ($user->role === Role::OWNER && $role !== Role::OWNER && ! User::where('role', Role::OWNER)->whereKeyNot($user->id)->exists()) {
            $err->writeln("<error>{$user->email} is the only owner. Make someone else owner first, then change this role.</error>");

            return self::FAILURE;
        }

        if (($user->role === Role::OWNER) !== ($role === Role::OWNER) && ! $this->typedBack($user)) {
            $err->writeln("<error>Not confirmed, nothing changed. To make or unmake {$user->email} an owner, type the email back (--confirm=<email>).</error>");

            return self::FAILURE;
        }

        $user->forceFill(['role' => $role])->save();
        $this->components->info("{$user->email} is now {$role}.");

        return self::SUCCESS;
    }

    private function typedBack(User $user): bool
    {
        $given = $this->option('confirm') ?? ($this->input->isInteractive() ? $this->ask("Owner status of {$user->email} changes. Type the email to confirm") : null);

        return mb_strtolower(trim((string) $given)) === $user->email;
    }
}
