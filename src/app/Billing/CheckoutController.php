<?php

namespace App\Billing;

use App\Ai\SafeLog;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** "Go pro": makes a Stripe Checkout Session (test mode) for the signed-in user and sends the browser there. Fulfilment is the webhook's job only. */
class CheckoutController extends Controller
{
    public function store(Request $request, StripeGateway $stripe): Response|RedirectResponse
    {
        if ((string) config('billing.secret') === '') {
            return redirect()->route('pricing', ['checkout' => 'unavailable']);
        }

        try {
            $url = $stripe->checkoutUrl(
                $request->user(),
                route('pricing', ['checkout' => 'success']),
                route('pricing', ['checkout' => 'cancelled']),
            );
        } catch (Throwable $e) {
            SafeLog::failure('Stripe checkout', $e, ['user_id' => $request->user()->id]);

            return redirect()->route('pricing', ['checkout' => 'unavailable']);
        }

        return Inertia::location($url);
    }
}
