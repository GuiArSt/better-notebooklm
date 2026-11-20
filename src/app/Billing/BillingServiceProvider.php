<?php

namespace App\Billing;

use Illuminate\Support\ServiceProvider;

class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(StripeGateway::class, StripeSdkGateway::class);
    }

    public function boot(): void
    {
        StripeKeyGuard::check(config('billing.secret'));
    }
}
