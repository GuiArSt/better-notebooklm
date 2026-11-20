<?php

namespace App\Billing;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One row per Stripe event handled: the idempotency key, and the record of the (test) payment. No card or personal data. */
class StripeEvent extends Model
{
    protected $table = 'stripe_events';

    protected $guarded = [];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
