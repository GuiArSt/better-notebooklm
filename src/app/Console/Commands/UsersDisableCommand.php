<?php

namespace App\Console\Commands;

use App\Auth\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UsersDisableCommand extends Command
{
    protected $signature = 'users:disable {email} {--undo : enable the user again}';

    protected $description = 'Block a user from signing in and keep all their data (the default way to "remove" someone); --undo reverses it';

    public function handle(): int
    {
        $user = User::where('email', mb_strtolower(trim($this->argument('email'))))->first();
        if (! $user) {
            $this->getOutput()->getErrorStyle()->writeln("<error>No user {$this->argument('email')}. Check users:list.</error>");

            return self::FAILURE;
        }

        if ($this->option('undo')) {
            $user->forceFill(['disabled_at' => null])->save();
            $this->components->info("{$user->email} is enabled again.");

            return self::SUCCESS;
        }

        if ($user->role === Role::OWNER && ! $user->isDisabled()
            && ! User::where('role', Role::OWNER)->whereNull('disabled_at')->whereKeyNot($user->id)->exists()) {
            $this->getOutput()->getErrorStyle()->writeln("<error>{$user->email} is the only owner. Make someone else owner first (users:role), then disable.</error>");

            return self::FAILURE;
        }

        $user->forceFill(['disabled_at' => $user->disabled_at ?? now(), 'remember_token' => Str::random(60)])->save();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $this->components->info("{$user->email} is disabled: no sign-in, data kept. Undo with users:disable {$user->email} --undo.");

        return self::SUCCESS;
    }
}
