<?php

namespace Tests\Feature\Auth;

use App\Auth\IssueInvitation;
use App\Mail\InvitationMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The whole live path in one go: Cloudflare -> cloudflared -> Caddy -> PHP. PHP sees a private peer, plain http,
 * and cloudflared's CF-Connecting-IP. Caddy may say X-Forwarded-Proto http or https; the page is https either way.
 */
class TunnelInvitationFlowTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string}> */
    public static function proxyProtos(): array
    {
        return ['caddy says http' => ['http'], 'caddy says https' => ['https'], 'caddy says nothing' => ['']];
    }

    #[DataProvider('proxyProtos')]
    public function test_an_invitation_link_opens_submits_and_signs_in_behind_the_tunnel(string $proto): void
    {
        config(['app.url' => 'https://betternotebookllm.example']);
        Mail::fake();
        app(IssueInvitation::class)('ana@example.test', 'member');
        $link = null;
        Mail::assertSent(InvitationMail::class, function (InvitationMail $m) use (&$link) {
            $link = $m->link;

            return true;
        });
        $this->assertStringStartsWith('https://', $link);

        $headers = ['CF-Connecting-IP' => '203.0.113.7', 'X-Forwarded-For' => '172.18.0.5', 'X-Forwarded-Host' => 'betternotebookllm.example'];
        if ($proto !== '') {
            $headers['X-Forwarded-Proto'] = $proto;
        }
        $behind = fn () => $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->withHeaders($headers);

        // PHP receives plain http; the browser used https.
        $page = $behind()->get(preg_replace('#^https://#', 'http://', $link));
        $page->assertOk();

        $action = $page->viewData('page')['props']['action'];
        $this->assertStringStartsWith('https://', $action);

        // The form posts to that action, again arriving as plain http.
        $behind()->post(preg_replace('#^https://#', 'http://', $action), [
            'name' => 'Ana', 'password' => 'a-long-enough-passphrase', 'password_confirmation' => 'a-long-enough-passphrase',
        ])->assertRedirect();

        $this->assertAuthenticatedAs(User::where('email', 'ana@example.test')->firstOrFail());
    }
}
