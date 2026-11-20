<?php

namespace Tests\Feature\Auth;

use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase;

    private function invite(string $email = 'new@example.com', string $role = 'reviewer'): string
    {
        Mail::fake();
        $this->artisan('invite', ['email' => $email, 'role' => $role])->assertSuccessful();

        $link = null;
        Mail::assertSent(InvitationMail::class, function (InvitationMail $mail) use (&$link) {
            $link = $mail->link;

            return true;
        });

        return $link;
    }

    private function accept(string $link, array $over = [])
    {
        return $this->post($link, array_merge([
            'name' => 'New Person',
            'password' => 'a-long-enough-passphrase',
            'password_confirmation' => 'a-long-enough-passphrase',
        ], $over));
    }

    public function test_invite_command_stores_a_hashed_token_and_mails_a_signed_link_for_72_hours(): void
    {
        $link = $this->invite();

        $invitation = Invitation::firstOrFail();
        $this->assertSame('new@example.com', $invitation->email);
        $this->assertSame('reviewer', $invitation->role);
        $this->assertEqualsWithDelta(72 * 3600, now()->diffInSeconds($invitation->expires_at, false), 5);

        parse_str(parse_url($link, PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('signature', $query);
        $this->assertArrayHasKey('expires', $query);
        $this->assertNotSame($query['token'], $invitation->token_hash);
        $this->assertSame(hash('sha256', $query['token']), $invitation->token_hash);
        $this->assertStringStartsWith(config('app.url'), $link);
    }

    public function test_invite_command_refuses_an_unknown_role(): void
    {
        Mail::fake();
        $this->artisan('invite', ['email' => 'a@example.com', 'role' => 'god'])->assertFailed();

        Mail::assertNothingSent();
        $this->assertSame(0, Invitation::count());
    }

    public function test_reinviting_replaces_the_old_link(): void
    {
        $old = $this->invite();
        $new = $this->invite();

        $this->get($old)->assertStatus(410);
        $this->get($new)->assertOk();
    }

    public function test_the_link_shows_the_set_password_page(): void
    {
        $this->get($this->invite())->assertInertia(fn (Assert $page) => $page
            ->component('auth/accept-invitation')
            ->where('email', 'new@example.com'));
    }

    public function test_accepting_creates_the_user_with_the_invited_role_and_signs_in(): void
    {
        $link = $this->invite('new@example.com', 'reviewer');

        $this->accept($link)->assertRedirect(route('dashboard', absolute: false));

        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('reviewer', $user->role);
        $this->assertSame('free', $user->tier);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull(Invitation::first()->accepted_at);
    }

    public function test_a_used_link_is_refused(): void
    {
        $link = $this->invite();
        $this->accept($link);
        auth()->logout();
        $this->app['auth']->forgetGuards();

        $this->get($link)->assertStatus(410);
        $this->accept($link, ['name' => 'Again'])->assertStatus(410);
        $this->assertSame(1, User::count());
    }

    public function test_an_expired_link_is_refused(): void
    {
        $link = $this->invite();

        $this->travel(73)->hours();

        $this->get($link)->assertForbidden();
        $this->accept($link)->assertForbidden();
        $this->assertSame(0, User::count());
    }

    public function test_a_tampered_or_wrong_token_link_is_refused(): void
    {
        $link = $this->invite();

        $this->get($link.'x')->assertForbidden();
        $this->get(preg_replace('/token=[^&]+/', 'token=wrong', $link))->assertForbidden();
        $this->assertSame(0, User::count());
    }

    public function test_a_short_password_is_refused(): void
    {
        $link = $this->invite();

        $this->accept($link, ['password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('password');
        $this->assertSame(0, User::count());
    }

    public function test_the_registration_route_is_closed(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'X', 'email' => 'x@example.com', 'password' => 'password-password', 'password_confirmation' => 'password-password',
        ])->assertStatus(404);
        $this->assertSame(0, User::count());
    }

    public function test_password_sign_in_is_rate_limited(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong']);
        }

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertStatus(429);
        $this->assertGuest();
    }
}
