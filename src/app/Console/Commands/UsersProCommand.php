<?php

namespace App\Console\Commands;

use App\Auth\GrantPro;
use App\Auth\RevokePro;
use App\Models\User;
use Illuminate\Console\Command;

class UsersProCommand extends Command
{
    protected $signature = 'users:pro {email} {state : on or off}';

    protected $description = 'Give a user pro for 30 days (on) or take it away (off), through GrantPro and RevokePro';

    public function handle(GrantPro $grant, RevokePro $revoke): int
    {
        $err = $this->getOutput()->getErrorStyle();
        $state = $this->argument('state');
        if (! in_array($state, ['on', 'off'], true)) {
            $err->writeln('<error>State must be on or off. Try again with one of those.</error>');

            return self::FAILURE;
        }

        $user = User::where('email', mb_strtolower(trim($this->argument('email'))))->first();
        if (! $user) {
            $err->writeln("<error>No user {$this->argument('email')}. Check users:list.</error>");

            return self::FAILURE;
        }

        $state === 'on' ? $grant($user) : $revoke($user);
        $user->refresh();
        $this->components->info("{$user->email}: tier {$user->tier}".($user->pro_until ? ", pro until {$user->pro_until}" : '').'.');

        return self::SUCCESS;
    }
}
