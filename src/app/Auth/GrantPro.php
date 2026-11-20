<?php

namespace App\Auth;

use App\Models\User;

/** Makes a user pro for 30 days. Called by the verified Stripe webhook only; buying again never stacks. */
class GrantPro
{
    public const DAYS = 30;

    public function __invoke(User $user): User
    {
        $user->forceFill(['tier' => 'pro', 'pro_until' => now()->addDays(self::DAYS)])->save();

        return $user;
    }
}
