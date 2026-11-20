<?php

namespace App\Models;

use App\Auth\Role;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string|null $password
 * @property string $role
 * @property string $tier
 * @property Carbon|null $pro_until
 * @property string|null $google_id
 * @property Carbon|null $disabled_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'google_id', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'pro_until' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    /** A live pro purchase: tier is pro and pro_until has not passed. Role plays no part here. */
    public function hasProPurchase(): bool
    {
        return $this->tier === 'pro' && ($this->pro_until === null || $this->pro_until->isFuture());
    }

    /** What the ledger and the Router read: pro for the owner and reviewers by role (D-048), else pro only with a live purchase. */
    public function effectiveTier(): string
    {
        return $this->role === Role::OWNER || $this->role === Role::REVIEWER || $this->hasProPurchase() ? 'pro' : 'free';
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    public function isOwner(): bool
    {
        return $this->role === Role::OWNER;
    }
}
