<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UsersListCommand extends Command
{
    protected $signature = 'users:list {--json : machine output on stdout}';

    protected $description = 'List users: email, role, purchased tier, pro until, status, last seen (from the sessions table)';

    public function handle(): int
    {
        $seen = DB::table('sessions')->whereNotNull('user_id')->groupBy('user_id')
            ->selectRaw('user_id, max(last_activity) as at')->pluck('at', 'user_id');

        $users = User::orderBy('id')->get()->map(fn (User $u) => [
            'email' => $u->email,
            'role' => $u->role,
            'tier' => $u->hasProPurchase() ? 'pro' : 'free',
            'pro_until' => $u->pro_until?->toDateTimeString(),
            'status' => $u->isDisabled() ? 'disabled' : 'active',
            'last_seen' => isset($seen[$u->id]) ? date('Y-m-d H:i', (int) $seen[$u->id]) : null,
        ]);

        if ($this->option('json')) {
            $this->line($users->toJson());

            return self::SUCCESS;
        }

        $this->table(['email', 'role', 'tier', 'pro until', 'status', 'last seen'], $users->map(fn ($u) => [
            $u['email'], $u['role'], $u['tier'], $u['pro_until'] ?? '-', $u['status'], $u['last_seen'] ?? 'never',
        ]));

        return self::SUCCESS;
    }
}
