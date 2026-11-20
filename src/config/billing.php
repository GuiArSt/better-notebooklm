<?php

/**
 * Stripe, in TEST MODE only (D-007): "Pro (demo)" is a one-off payment that buys 30 days of pro (GrantPro). No real money moves.
 * The keys are the owner's, in the untracked .env; a live key stops the app at boot (App\Billing\StripeKeyGuard).
 */
return [
    'key' => env('STRIPE_KEY'),                       // pk_test_… (not used by the server; kept so the template names it)
    'secret' => env('STRIPE_SECRET'),                 // sk_test_…
    'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'), // whsec_…
    'webhook_tolerance' => 300,                       // seconds a signed event stays valid

    'price' => [
        'currency' => 'usd',
        'amount' => 500,                              // US cents: $5.00 of TEST money, matching the pro credit cap ($5)
        'name' => 'Pro (demo): 30 days',
    ],
];
