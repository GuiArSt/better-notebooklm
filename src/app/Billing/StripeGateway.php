<?php

namespace App\Billing;

use App\Models\User;

/** The one call the app makes to Stripe, behind an interface so tests never reach the network. */
interface StripeGateway
{
    /** @return string the address of the hosted Checkout page */
    public function checkoutUrl(User $user, string $successUrl, string $cancelUrl): string;
}
