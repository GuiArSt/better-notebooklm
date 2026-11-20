<?php

namespace App\Auth;

use App\Models\User;

class RevokePro
{
    public function __invoke(User $user): User
    {
        $user->forceFill(['tier' => 'free', 'pro_until' => null])->save();

        return $user;
    }
}
