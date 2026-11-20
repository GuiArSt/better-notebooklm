<?php

namespace App\Console\Commands;

use App\Auth\GrantPro;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DevSeedTesters extends Command
{
    protected $signature = 'dev:seed-testers';

    protected $description = 'Dev only: make (or reset) owner@dev.test, reviewer@dev.test, free@dev.test and pro@dev.test (30 days of pro, through GrantPro), and print their new passwords to this console';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run: APP_ENV is production. These test users exist only in dev.');

            return self::FAILURE;
        }

        $rows = [];
        foreach (['owner' => 'owner', 'reviewer' => 'reviewer', 'free' => 'member', 'pro' => 'member'] as $name => $role) {
            $password = Str::random(20);
            $user = User::firstOrNew(['email' => "$name@dev.test"]);
            $user->forceFill([
                'name' => ucfirst($name).' (dev tester)', 'password' => Hash::make($password), 'role' => $role,
                'tier' => 'free', 'pro_until' => null, 'email_verified_at' => now(),
            ])->save();
            if ($name === 'pro') {
                // As the Stripe flow does it (D-012): the same action, 30 days of pro.
                app(GrantPro::class)($user);
            }
            $rows[] = ["$name@dev.test", $role, $password];
        }

        $this->components->info('Dev test users (the passwords are shown here once; running the command again makes new ones):');
        $this->table(['email', 'role', 'password'], $rows);

        return self::SUCCESS;
    }
}
