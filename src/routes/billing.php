<?php

use App\Billing\CheckoutController;
use App\Billing\WebhookController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

// "Go pro" (inside the auth + verified group in web.php): a Stripe Checkout Session in test mode.
Route::post('billing/checkout', [CheckoutController::class, 'store'])->middleware('throttle:6,1')->name('billing.checkout');

// Stripe calls this itself: no session, no CSRF token; the signature is the proof.
Route::withoutMiddleware(['auth', 'verified', PreventRequestForgery::class, ValidateCsrfToken::class])->post('stripe/webhook', WebhookController::class)->name('stripe.webhook');
