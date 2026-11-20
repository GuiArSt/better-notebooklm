<?php

namespace Tests\Feature\Auth;

use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** D-022: no mail service. The owner hands the printed link to the tester; there is no "forgot password". */
class NoMailDemoTest extends TestCase
{
    use RefreshDatabase;

    private function printedLink(string $email, string $role = 'member'): string
    {
        $this->assertSame(0, Artisan::call('invite', ['email' => $email, 'role' => $role]));
        preg_match('#https?://\S+/invitation/\d+\?\S+#', Artisan::output(), $m);
        $this->assertNotEmpty($m, 'the invite command prints the link');

        return $m[0];
    }

    public function test_invite_prints_a_working_single_use_link_whatever_the_mailer(): void
    {
        config(['mail.default' => 'log']);
        $link = $this->printedLink('tester@example.com', 'reviewer');

        $this->get($link)->assertOk();
        $this->post($link, ['name' => 'Tess', 'password' => 'a-long-enough-passphrase', 'password_confirmation' => 'a-long-enough-passphrase'])
            ->assertRedirect();
        $this->assertSame('reviewer', User::where('email', 'tester@example.com')->value('role'));

        auth()->logout();
        $this->app['auth']->forgetGuards();
        $this->get($link)->assertStatus(410);
    }

    public function test_invite_prints_nothing_secret_but_the_link(): void
    {
        $this->printedLink('tester@example.com');
        $out = Artisan::output();

        $this->assertStringNotContainsString(config('app.key'), $out);
        $this->assertStringNotContainsString(Invitation::first()->token_hash, $out, 'the stored hash is not printed');
    }

    public function test_the_mail_is_still_sent_when_a_mailer_is_set(): void
    {
        Mail::fake();
        $this->artisan('invite', ['email' => 'tester@example.com', 'role' => 'member'])->assertSuccessful();

        Mail::assertSent(InvitationMail::class);
    }

    public function test_the_reset_routes_are_off_and_the_login_page_has_no_forgot_link(): void
    {
        $this->get('/forgot-password')->assertNotFound();
        $this->post('/forgot-password', ['email' => 'a@example.com'])->assertNotFound();
        $this->get('/reset-password/abc')->assertNotFound();
        $this->post('/reset-password', ['token' => 'x', 'email' => 'a@example.com', 'password' => 'x'])->assertNotFound();

        $this->get('/login')->assertOk()->assertInertia(fn ($page) => $page->component('auth/login')->missing('canResetPassword'));
    }

    public function test_re_inviting_an_existing_user_replaces_the_link_and_sets_a_new_password(): void
    {
        $user = User::factory()->create(['email' => 'locked@example.com', 'role' => 'member']);
        DB::table('sessions')->insert(['id' => 'old', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $first = $this->printedLink('locked@example.com');
        $second = $this->printedLink('locked@example.com');
        $this->get($first)->assertStatus(410);          // replaced
        $this->get($second)->assertOk();                // the new link works for an existing address

        $this->post($second, ['password' => 'a-brand-new-passphrase', 'password_confirmation' => 'a-brand-new-passphrase'])
            ->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::where('email', 'locked@example.com')->count());
        $this->assertTrue(\Hash::check('a-brand-new-passphrase', $user->fresh()->password));
        $this->assertSame(0, DB::table('sessions')->where('id', 'old')->count(), 'old sessions end');

        auth()->logout();
        $this->app['auth']->forgetGuards();
        $this->post(route('login.store'), ['email' => 'locked@example.com', 'password' => 'password'])->assertSessionHasErrors();
        $this->post(route('login.store'), ['email' => 'locked@example.com', 'password' => 'a-brand-new-passphrase'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_invite_prints_the_link_and_exits_0_when_the_mail_transport_is_broken(): void
    {
        // The deploy template used to point SMTP at a mail service nobody set up.
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 1]);

        $this->assertSame(0, Artisan::call('invite', ['email' => 'tester@example.com', 'role' => 'member']));
        $out = Artisan::output();

        $this->assertMatchesRegularExpression('#https?://\S+/invitation/\d+\?\S+#', $out);
        $this->assertStringContainsString('still valid', $out);
        $this->assertSame(1, Invitation::pending()->count());
    }

    public function test_with_the_log_mailer_the_link_is_printed_once_and_no_mail_is_written(): void
    {
        Mail::fake();
        config(['mail.default' => 'log']);

        $this->printedLink('tester@example.com');

        Mail::assertNothingSent();
    }

    public function test_the_admin_page_shows_the_link_when_there_is_no_mail_service(): void
    {
        config(['mail.default' => 'log']);
        $owner = User::factory()->withTwoFactor()->create(['role' => 'owner']);

        $this->actingAs($owner)->post(route('admin.invitations.store'), ['email' => 'n@example.com', 'role' => 'member'])
            ->assertSessionHas('status', fn ($s) => str_contains($s, '/invitation/'));
    }

    public function test_an_old_remember_me_cookie_stops_working_after_a_re_invite(): void
    {
        $user = User::factory()->create(['email' => 'locked@example.com']);
        $name = Auth::guard('web')->getRecallerName();

        // A real sign-in with "remember me": take the cookie the browser would keep.
        $cookie = $this->post(route('login.store'), ['email' => 'locked@example.com', 'password' => 'password', 'remember' => 'on'])
            ->getCookie($name, false)->getValue();
        $oldToken = $user->fresh()->remember_token;
        // (not logout(): that would itself rotate the remember token)
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->withUnencryptedCookie($name, $cookie)->get(route('notebooks.index'))->assertOk();   // the cookie works before

        $this->unencryptedCookies = [];
        $link = $this->printedLink('locked@example.com');
        // (not logout(): that would itself rotate the remember token)
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->post($link, ['password' => 'a-brand-new-passphrase', 'password_confirmation' => 'a-brand-new-passphrase']);

        $this->assertNotSame($oldToken, $user->fresh()->remember_token);
        // (not logout(): that would itself rotate the remember token)
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->withUnencryptedCookie($name, $cookie)->get(route('notebooks.index'))->assertRedirect(route('login'));
    }

    public function test_a_re_invited_account_with_two_factor_does_not_sign_in_on_the_new_password_alone(): void
    {
        $user = User::factory()->withTwoFactor()->create(['email' => 'safe@example.com']);

        $link = $this->printedLink('safe@example.com');
        $this->post($link, ['password' => 'a-brand-new-passphrase', 'password_confirmation' => 'a-brand-new-passphrase'])
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertTrue(\Hash::check('a-brand-new-passphrase', $user->fresh()->password));
    }

    public function test_a_mail_failure_logs_only_the_error_class_and_the_invitation_id(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 1]);
        $logged = [];
        Log::listen(function ($m) use (&$logged) {
            $logged[] = [$m->message, $m->context];
        });

        Artisan::call('invite', ['email' => 'secret.person@example.test', 'role' => 'member']);

        $failure = collect($logged)->first(fn ($l) => $l[0] === 'Invitation mail failed');
        $this->assertNotNull($failure, 'the failure is logged');
        $this->assertSame(['error', 'invitation_id'], array_keys($failure[1]));
        $this->assertSame(Invitation::value('id'), $failure[1]['invitation_id']);
        $dump = json_encode($logged);
        $this->assertStringNotContainsString('secret.person', $dump, 'no address in the log');
        $this->assertStringNotContainsString('/invitation/', $dump, 'no link in the log');
        $this->assertStringNotContainsString('exception', $dump, 'no trace');
    }

    public function test_the_admin_page_shows_the_link_when_the_mail_fails(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 1]);
        $owner = User::factory()->create(['role' => 'owner']);

        $this->actingAs($owner)->post(route('admin.invitations.store'), ['email' => 'n@example.com', 'role' => 'member'])
            ->assertSessionHas('status', fn ($s) => str_contains($s, '/invitation/') && ! str_contains($s, 'Invitation sent'));
    }
}
