<?php

namespace App\Billing;

use App\Ai\SafeLog;
use App\Auth\GrantPro;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * Stripe's webhook: the only place pro is granted for a payment. The signature is checked against STRIPE_WEBHOOK_SECRET on the raw body;
 * each event id is processed once (a unique row), so a retry or a replay grants nothing more.
 */
class WebhookController extends Controller
{
    public function __invoke(Request $request, GrantPro $grantPro): JsonResponse
    {
        $secret = (string) config('billing.webhook_secret');
        if ($secret === '') {
            return response()->json(['error' => 'webhook not set up'], 503);
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                $secret,
                (int) config('billing.webhook_tolerance'),
            );
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return response()->json(['error' => 'invalid signature'], 400);
        }

        if ($event->type !== 'checkout.session.completed') {
            SafeLog::warning('Stripe event ignored', ['event_id' => $event->id, 'reason' => 'not a checkout.session.completed event', 'type' => $event->type]);

            return response()->json(['ignored' => $event->type]);
        }

        $session = $event->data->object;
        // Test mode only, and only a paid session of this app's own.
        if ($event->livemode || ($session->payment_status ?? null) !== 'paid' || ($session->metadata->app ?? null) !== 'better-notebooklm') {
            SafeLog::warning('Stripe event ignored', ['event_id' => $event->id, 'reason' => 'not a paid test-mode session of this app']);

            return response()->json(['ignored' => 'not a paid test-mode session of this app']);
        }

        $user = User::find((int) ($session->client_reference_id ?? 0));
        if (! $user) {
            SafeLog::warning('Stripe event ignored', ['event_id' => $event->id, 'reason' => 'unknown user']);

            return response()->json(['ignored' => 'unknown user']);
        }

        try {
            DB::transaction(function () use ($event, $session, $user, $grantPro) {
                StripeEvent::create([
                    'event_id' => $event->id,
                    'type' => $event->type,
                    'session_id' => (string) $session->id,
                    'user_id' => $user->id,
                    'amount' => (int) ($session->amount_total ?? 0),
                    'currency' => (string) ($session->currency ?? ''),
                ]);
                $grantPro($user);
            });
        } catch (UniqueConstraintViolationException) {
            SafeLog::warning('Stripe event ignored', ['event_id' => $event->id, 'reason' => 'duplicate (already handled)']);

            return response()->json(['duplicate' => $event->id]);
        }

        return response()->json(['granted' => $user->id]);
    }
}
