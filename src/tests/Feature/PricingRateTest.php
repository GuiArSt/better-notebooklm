<?php

namespace Tests\Feature;

use App\Ai\Pricing;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** HC-14 (Hipparchus): a model id with a dot keeps its own price; a model with no price is billed the default rate, and that is logged. */
class PricingRateTest extends TestCase
{
    public function test_a_model_id_with_a_dot_is_priced_by_its_own_rate(): void
    {
        config(['pricing.models' => ['x-1.5' => ['input' => 1_000_000, 'output' => 2_000_000]], 'pricing.default' => ['input' => 9_000_000, 'output' => 9_000_000]]);
        $logged = [];
        Event::listen(MessageLogged::class, function ($e) use (&$logged) {
            $logged[] = $e->message;
        });

        $this->assertSame(['input' => 1_000_000, 'output' => 2_000_000], app(Pricing::class)->rate('x-1.5'));
        $this->assertSame(1 + 2, app(Pricing::class)->cost('x-1.5', 1, 1));
        $this->assertSame([], $logged, 'a priced model logs nothing');
    }

    public function test_a_model_with_no_price_is_billed_the_default_and_the_log_says_which(): void
    {
        config(['pricing.models' => [], 'pricing.default' => ['input' => 2_000_000, 'output' => 10_000_000]]);
        $logged = [];
        Event::listen(MessageLogged::class, function ($e) use (&$logged) {
            $logged[] = $e->message.json_encode($e->context);
        });

        $this->assertSame(12, app(Pricing::class)->cost('mystery-model', 1, 1));

        $this->assertStringContainsString('No price for the model', $logged[0]);
        $this->assertStringContainsString('mystery-model', $logged[0]);
    }
}
