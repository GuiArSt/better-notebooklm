<?php

use App\Billing\BillingServiceProvider;
use App\Icons\IconServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    BillingServiceProvider::class,
    IconServiceProvider::class,
];
