<?php

namespace Tests\Feature\Auth;

use App\Auth\GrantPro;
use App\Mail\InvitationMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** R2A-1, R2A-2, R2A-3: behind Caddy and the Cloudflare tunnel. */
class BehindTheProxyTest extends TestCase
{
    use RefreshDatabase;

    private function viaProxy(string $clientIp): static
    {
        // The proxy (Caddy) connects from a private address and passes the visitor in CF-Connecting-IP.
        return $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.2'])
            ->withHeaders(['CF-Connecting-IP' => $clientIp]);
    }

    public function test_the_per_ip_limit_counts_each_visitor_not_the_proxy(): void
    {
        // The invitation limiter is 10 a minute per IP.
        for ($i = 0; $i < 10; $i++) {
            $this->viaProxy('203.0.113.10')->get('/invitation/1')->assertStatus(404);
        }
        $this->viaProxy('203.0.113.10')->get('/invitation/1')->assertStatus(429);

        // A different visitor behind the same proxy is not locked out.
        $this->viaProxy('203.0.113.11')->get('/invitation/1')->assertStatus(404);
    }

    public function test_the_forwarded_header_is_ignored_from_a_public_address(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
                ->withHeaders(['CF-Connecting-IP' => '203.0.113.'.$i])->get('/invitation/1');
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->withHeaders(['CF-Connecting-IP' => '203.0.113.99'])->get('/invitation/1')->assertStatus(429);
    }

    public function test_the_mailed_link_and_the_form_action_are_https_when_app_url_is_https(): void
    {
        config(['app.url' => 'https://betternotebookllm.example']);
        Mail::fake();
        $this->artisan('invite', ['email' => 'new@example.com', 'role' => 'member'])->assertSuccessful();

        $link = null;
        Mail::assertSent(InvitationMail::class, function (InvitationMail $mail) use (&$link) {
            $link = $mail->link;

            return true;
        });
        $this->assertStringStartsWith('https://betternotebookllm.example/invitation/', $link);

        // The tunnel terminates TLS: Caddy speaks plain http to the app and says so in X-Forwarded-Proto.
        $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.2'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'betternotebookllm.example'])
            ->get($link)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/accept-invitation')
                ->where('action', fn ($action) => str_starts_with($action, 'https://')));
    }

    public function test_effective_tier_is_free_once_pro_until_has_passed(): void
    {
        $user = app(GrantPro::class)(User::factory()->create());
        $this->assertSame('pro', $user->effectiveTier());

        $this->travel(31)->days();

        $this->assertSame('free', $user->fresh()->effectiveTier());
    }

    public function test_the_access_middleware_runs_on_the_real_web_routes(): void
    {
        $user = app(GrantPro::class)(User::factory()->create());
        $this->travel(31)->days();

        $this->actingAs($user->fresh())->get(route('notebooks.index'))->assertOk();

        $this->assertSame('free', $user->fresh()->getRawOriginal('tier'));
    }
}
