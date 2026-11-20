<?php

namespace App\Billing;

use RuntimeException;

/** Janus §3.2: a live key is refused at boot. Only test keys are accepted, so no real money can ever move. */
class StripeKeyGuard
{
    public static function check(?string $secret): void
    {
        if ($secret === null || $secret === '') {
            return; // not set up: checkout says so; nothing can charge
        }

        if (! str_starts_with($secret, 'sk_test_')) {
            throw new RuntimeException('STRIPE_SECRET must be a test key (sk_test_…). A live or unknown key is refused: this app only runs Stripe in test mode.');
        }
    }
}
