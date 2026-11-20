<?php

namespace Tests\Feature\Billing;

use App\Billing\BillingServiceProvider;
use App\Billing\StripeEvent;
use App\Billing\StripeGateway;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

/** Stripe in test mode (D-007), with Stripe mocked: nothing here reaches the network. */
class StripeCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.secret' => 'sk_test_x', 'billing.webhook_secret' => self::SECRET]);
    }

    /** A signed Stripe event, built the way Stripe signs it (t=…,v1=hmac_sha256(secret, "t.payload")). */
    private function signed(array $event, ?string $secret = self::SECRET, ?int $time = null): array
    {
        $payload = json_encode($event);
        $t = $time ?? time();
        $sig = hash_hmac('sha256', $t.'.'.$payload, (string) $secret);

        return [$payload, ['HTTP_STRIPE_SIGNATURE' => "t=$t,v1=$sig", 'CONTENT_TYPE' => 'application/json']];
    }

    private function completed(User $user, string $id = 'evt_1', array $override = []): array
    {
        return array_replace_recursive([
            'id' => $id, 'object' => 'event', 'type' => 'checkout.session.completed', 'livemode' => false,
            'data' => ['object' => [
                'id' => 'cs_test_1', 'object' => 'checkout.session', 'payment_status' => 'paid', 'client_reference_id' => (string) $user->id,
                'amount_total' => 500, 'currency' => 'usd', 'metadata' => ['app' => 'better-notebooklm', 'user_id' => (string) $user->id],
            ]],
        ], $override);
    }

    private function webhook(array $event, ?string $secret = self::SECRET)
    {
        [$payload, $server] = $this->signed($event, $secret);

        return $this->call('POST', '/stripe/webhook', [], [], [], $server, $payload);
    }

    public function test_checkout_creates_a_session_for_the_signed_in_user_and_sends_the_browser_to_stripe(): void
    {
        $user = User::factory()->create();
        $seen = [];
        $this->app->instance(StripeGateway::class, new class($seen) implements StripeGateway
        {
            public function __construct(public array &$seen) {}

            public function checkoutUrl(User $user, string $successUrl, string $cancelUrl): string
            {
                $this->seen = [$user->id, $successUrl, $cancelUrl];

                return 'https://checkout.stripe.test/pay/cs_test_1';
            }
        });

        $response = $this->actingAs($user)->withHeaders(['X-Inertia' => 'true'])->post('/billing/checkout');

        $response->assertStatus(409)->assertHeader('X-Inertia-Location', 'https://checkout.stripe.test/pay/cs_test_1');
        $this->assertSame($user->id, $seen[0]);
        $this->assertStringEndsWith('/pricing?checkout=success', $seen[1]);
        $this->assertStringEndsWith('/pricing?checkout=cancelled', $seen[2]);
        $this->assertSame('free', $user->fresh()->tier, 'the browser being sent to Stripe grants nothing');
    }

    public function test_checkout_needs_a_signed_in_user_and_says_so_when_stripe_is_not_set_up(): void
    {
        $this->post('/billing/checkout')->assertRedirect('/login');

        config(['billing.secret' => '']);
        $this->actingAs(User::factory()->create())->post('/billing/checkout')->assertRedirect(route('pricing', ['checkout' => 'unavailable']));
    }

    public function test_a_forged_or_unsigned_webhook_is_refused_and_grants_nothing(): void
    {
        $user = User::factory()->create();

        $this->webhook($this->completed($user), 'whsec_wrong')->assertStatus(400);
        $this->call('POST', '/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($this->completed($user)))->assertStatus(400);
        [$payload, $server] = $this->signed($this->completed($user), self::SECRET, time() - 3600); // a replay of an old, once-valid signature
        $this->call('POST', '/stripe/webhook', [], [], [], $server, $payload)->assertStatus(400);

        $this->assertSame('free', $user->fresh()->tier);
        $this->assertSame(0, StripeEvent::count());
    }

    public function test_a_valid_webhook_grants_pro_once_and_a_replay_grants_nothing_more(): void
    {
        $user = User::factory()->create();

        $this->webhook($this->completed($user))->assertOk()->assertJson(['granted' => $user->id]);
        $first = $user->fresh();
        $this->assertSame('pro', $first->tier);
        $this->assertNotNull($first->pro_until);

        $this->travel(5)->days();
        $this->webhook($this->completed($user))->assertOk()->assertJson(['duplicate' => 'evt_1']);
        $this->assertEquals($first->pro_until, $user->fresh()->pro_until, 'the same event id extends nothing');
        $this->assertSame(1, StripeEvent::count());
    }

    public function test_only_a_paid_test_mode_session_of_this_app_counts(): void
    {
        $user = User::factory()->create();

        $this->webhook($this->completed($user, 'evt_a', ['data' => ['object' => ['payment_status' => 'unpaid']]]))->assertOk();
        $this->webhook($this->completed($user, 'evt_b', ['livemode' => true]))->assertOk();
        $this->webhook($this->completed($user, 'evt_c', ['data' => ['object' => ['metadata' => ['app' => 'someone-else']]]]))->assertOk();
        $this->webhook($this->completed($user, 'evt_d', ['type' => 'payment_intent.created']))->assertOk();
        $this->webhook($this->completed($user, 'evt_e', ['data' => ['object' => ['client_reference_id' => '999999']]]))->assertOk();

        $this->assertSame('free', $user->fresh()->tier);
        $this->assertSame(0, StripeEvent::count());
    }

    /** Laravel switches the CSRF check off while testing, so the tests above cannot see it: ask the router what really runs on the webhook. */
    public function test_the_webhook_route_does_not_run_the_csrf_or_auth_middleware_stripe_cannot_satisfy(): void
    {
        $route = app('router')->getRoutes()->getByName('stripe.webhook');
        $middleware = app('router')->gatherRouteMiddleware($route);

        foreach ([PreventRequestForgery::class, ValidateCsrfToken::class, 'auth', 'verified'] as $blocked) {
            $this->assertNotContains($blocked, $middleware, "$blocked must not run on the webhook");
        }
        $this->assertContains('Illuminate\\Session\\Middleware\\StartSession', $middleware, 'sanity: the web group is still the base');
    }

    public function test_a_live_key_stops_the_app_at_boot(): void
    {
        config(['billing.secret' => 'sk_'.'live_'.'not-a-real-key']); // built from parts: a secret scanner must not read a test value as a key

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('test key');
        (new BillingServiceProvider($this->app))->boot();
    }

    public function test_test_keys_and_an_unset_key_are_accepted_at_boot(): void
    {
        foreach (['sk_test_abc', '', null] as $secret) {
            config(['billing.secret' => $secret]);
            (new BillingServiceProvider($this->app))->boot();
        }
        $this->addToAssertionCount(1);
    }

    public function test_the_template_names_the_stripe_variables_and_a_live_key_is_not_in_it(): void
    {
        $template = (string) file_get_contents(base_path('../deploy/.env.example'));

        foreach (['STRIPE_KEY=', 'STRIPE_SECRET=', 'STRIPE_WEBHOOK_SECRET='] as $line) {
            $this->assertStringContainsString("\n$line\n", $template);
        }
        $this->assertStringNotContainsString('sk_live', $template);
    }

    /** HB-4 (Argus's backend review): every "ignored" branch says why in the log, with the event id; the answer stays 200. */
    public function test_every_ignored_event_is_logged_with_its_id_and_the_reason_and_still_answers_200(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function ($e) use (&$logged) {
            $logged[] = $e->message.json_encode($e->context);
        });
        $user = User::factory()->create();

        $this->webhook($this->completed($user, 'evt_unpaid', ['data' => ['object' => ['payment_status' => 'unpaid']]]))->assertOk();
        $this->webhook($this->completed($user, 'evt_other', ['type' => 'payment_intent.created']))->assertOk();
        $this->webhook($this->completed($user, 'evt_ghost', ['data' => ['object' => ['client_reference_id' => '999999']]]))->assertOk();
        $this->webhook($this->completed($user, 'evt_ok'))->assertOk();
        $this->webhook($this->completed($user, 'evt_ok'))->assertOk()->assertJson(['duplicate' => 'evt_ok']);

        $log = implode("\n", $logged);
        foreach ([['evt_unpaid', 'not a paid test-mode session'], ['evt_other', 'not a checkout.session.completed event'], ['evt_ghost', 'unknown user'], ['evt_ok', 'duplicate']] as [$id, $reason]) {
            $this->assertMatchesRegularExpression('/Stripe event ignored.*'.$id.'.*'.preg_quote($reason, '/').'/', $log, "$id: $reason");
        }
    }
}
