<?php

namespace App\Billing;

use App\Models\User;
use Stripe\StripeClient;

class StripeSdkGateway implements StripeGateway
{
    public function checkoutUrl(User $user, string $successUrl, string $cancelUrl): string
    {
        // CheckoutController has already refused an unset secret: it is not checked twice.
        $secret = (string) config('billing.secret');

        $price = config('billing.price');
        $session = (new StripeClient($secret))->checkout->sessions->create([
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => ['currency' => $price['currency'], 'unit_amount' => $price['amount'], 'product_data' => ['name' => $price['name']]],
            ]],
            // The user is known by id only: no e-mail or name goes to Stripe.
            'client_reference_id' => (string) $user->id,
            'metadata' => ['app' => 'better-notebooklm', 'user_id' => (string) $user->id],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ]);

        return (string) $session->url;
    }
}
