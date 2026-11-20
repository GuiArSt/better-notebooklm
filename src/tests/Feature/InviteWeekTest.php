<?php

namespace Tests\Feature;

use App\Auth\IssueInvitation;
use App\Models\Invitation;
use App\Models\Notebook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialUser;
use Mockery;
use Tests\TestCase;

/** scripts/demo/invite-week.php: a seven-day invitation with the account ready; the link and Google sign-in both land in that account. */
class InviteWeekTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00:00');
        config(['mail.default' => 'log', 'app.url' => 'https://demo.example.test']);
    }

    /** @return array{out: string, link: ?string} */
    private function invite(string $email = 'rae@example.test', array $env = []): array
    {
        $env += ['INVITE_EMAIL' => $email];
        foreach ($env as $k => $v) {
            putenv("$k=$v");
        }
        ob_start();
        try {
            include base_path('../scripts/demo/invite-week.php');
        } finally {
            $out = (string) ob_get_clean();
            foreach (array_keys($env) as $k) {
                putenv($k);
            }
        }
        preg_match('/^link: (\S+)$/m', $out, $m);

        return ['out' => $out, 'link' => $m[1] ?? null];
    }

    private function showcaseFor(int $userId): int
    {
        $nb = Notebook::create(['user_id' => $userId, 'title' => 'Showcase'])->id;
        DB::table('studio_items')->insert(['notebook_id' => $nb, 'user_id' => $userId, 'kind' => 'briefing', 'title' => 'B', 'status' => 'done', 'step_n' => 0, 'step_total' => 0, 'content' => 'Hallo', 'created_at' => now(), 'updated_at' => now()]);

        return $nb;
    }

    public function test_it_invites_for_seven_days_signs_the_link_for_that_expiry_and_prints_only_the_link_and_the_expiry(): void
    {
        $r = $this->invite();

        $invitation = Invitation::where('email', 'rae@example.test')->firstOrFail();
        $this->assertSame('reviewer', $invitation->role);
        $this->assertTrue($invitation->expires_at->equalTo(now()->addDays(7)));
        parse_str((string) parse_url($r['link'], PHP_URL_QUERY), $q);
        $this->assertSame(now()->addDays(7)->timestamp, (int) $q['expires'], 'the link is signed for the same expiry');
        $this->assertTrue($invitation->tokenMatches($q['token']), 'the token is the one the app issued');
        $this->assertStringStartsWith('https://demo.example.test/invitation/'.$invitation->id.'?', $r['link'], 'built from APP_URL');
        $this->assertStringContainsString('expires: 2026-11-27T10:00:00+00:00 (7 days)', $r['out']);
        $this->assertDoesNotMatchRegularExpression('/password: |secret|Passage|Hallo/', $r['out']);
        $this->assertSame(1, Invitation::count());
    }

    public function test_it_creates_the_account_with_no_password_unverified_and_the_invited_role(): void
    {
        $this->invite('rae@example.test', ['INVITE_NAME' => 'Rae Reviewer']);

        $user = User::where('email', 'rae@example.test')->firstOrFail();
        $this->assertSame('Rae Reviewer', $user->name);
        $this->assertSame('reviewer', $user->role);
        $this->assertNull($user->password);
        $this->assertNull($user->email_verified_at);
        $this->assertNull($user->google_id);
    }

    public function test_the_name_defaults_to_the_part_before_the_at_sign(): void
    {
        $this->invite('lena.ruiz@example.test');

        $this->assertSame('lena.ruiz', User::where('email', 'lena.ruiz@example.test')->value('name'));
    }

    public function test_the_link_opens_on_day_zero_and_for_a_week_while_the_old_72_hour_link_fails_after_its_time(): void
    {
        $week = $this->invite('rae@example.test')['link'];
        $old = $this->oldLink('old@example.test');

        $this->get($week)->assertOk();
        $this->get($old)->assertOk();

        Carbon::setTestNow(now()->addHours(73));
        $this->get($week)->assertOk();                          // day 3: still valid
        $this->get($old)->assertForbidden();                    // the 72-hour signature has expired

        Carbon::setTestNow(now()->addDays(4));                  // 7 days and 1 hour after the start
        $this->get($week)->assertForbidden();
    }

    /** What IssueInvitation makes by itself: a link for 72 hours. */
    private function oldLink(string $email): string
    {
        $issue = app(IssueInvitation::class);
        $issue($email, 'reviewer');

        return (string) $issue->link;
    }

    public function test_the_link_sets_a_password_in_the_precreated_account_and_the_showcase_is_there_at_the_first_sign_in(): void
    {
        $r = $this->invite();
        $user = User::where('email', 'rae@example.test')->firstOrFail();
        $nb = $this->showcaseFor($user->id);

        $page = $this->get($r['link'])->assertOk();
        $this->assertStringContainsString('rae@example.test', $page->getContent());
        $this->post($r['link'], ['password' => 'a-long-enough-password-1', 'password_confirmation' => 'a-long-enough-password-1'])->assertRedirect();

        $this->assertSame(1, User::where('email', 'rae@example.test')->count(), 'no second account');
        $this->assertAuthenticatedAs($user->fresh());
        $this->assertNotNull($user->fresh()->password);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertSame('reviewer', $user->fresh()->role);
        $this->assertSame($nb, (int) DB::table('notebooks')->where('user_id', $user->id)->value('id'), 'the showcase is still theirs');
        $this->getJson("/notebooks/$nb/studio")->assertOk()->assertJsonCount(1, 'items');
        // Single use: the same link again, by someone not signed in, no longer works.
        auth()->logout();
        $this->get($r['link'])->assertStatus(410);
    }

    public function test_google_sign_in_for_that_address_lands_in_the_same_account_any_time_in_the_week_even_before_the_link_is_used(): void
    {
        $r = $this->invite();
        $user = User::where('email', 'rae@example.test')->firstOrFail();
        $nb = $this->showcaseFor($user->id);
        Carbon::setTestNow(now()->addDays(6));

        $social = (new SocialUser)->map(['id' => 'g-777', 'name' => 'Rae R', 'email' => 'rae@example.test']);
        $social->user = ['email_verified' => true];
        $driver = Mockery::mock();
        $driver->shouldReceive('user')->andReturn($social);
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

        $this->get(route('google.callback'))->assertRedirect(route('dashboard', absolute: false));

        $this->assertSame(1, User::where('email', 'rae@example.test')->count(), 'no second account');
        $this->assertAuthenticatedAs($user->fresh());
        $this->assertSame('g-777', $user->fresh()->google_id);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertSame('reviewer', $user->fresh()->role);
        $this->assertSame($nb, (int) DB::table('notebooks')->where('user_id', $user->id)->value('id'));
        $this->getJson("/notebooks/$nb/studio")->assertOk()->assertJsonCount(1, 'items');
        $this->assertNotNull($r['link']);
    }

    public function test_after_google_the_link_still_works_to_set_a_password_for_the_same_account(): void
    {
        $r = $this->invite();
        $user = User::where('email', 'rae@example.test')->firstOrFail();
        $user->forceFill(['google_id' => 'g-1', 'email_verified_at' => now()])->save();

        $this->post($r['link'], ['password' => 'another-long-password-2', 'password_confirmation' => 'another-long-password-2'])->assertRedirect();

        $this->assertSame(1, User::where('email', 'rae@example.test')->count());
        $this->assertAuthenticatedAs($user->fresh());
        $this->assertSame('g-1', $user->fresh()->google_id);
    }

    public function test_running_it_again_replaces_the_older_link_and_leaves_the_account_alone(): void
    {
        $first = $this->invite()['link'];
        $user = User::where('email', 'rae@example.test')->firstOrFail();
        $nb = $this->showcaseFor($user->id);
        Carbon::setTestNow(now()->addDay());

        $second = $this->invite();

        $this->assertStringContainsString('already existed (left as it was)', $second['out']);
        $this->assertSame(1, User::count());
        $this->assertSame($nb, (int) DB::table('notebooks')->where('user_id', $user->id)->value('id'));
        $this->get($first)->assertStatus(410);
        $this->get($second['link'])->assertOk();
        $this->assertSame(1, Invitation::pending()->where('email', 'rae@example.test')->count());
    }

    public function test_an_address_with_a_working_account_is_refused_unless_you_say_so(): void
    {
        User::factory()->create(['email' => 'busy@example.test', 'password' => 'a-real-password-123456']);

        $r = $this->invite('busy@example.test');

        $this->assertStringContainsString('REFUSED: that address already has a working account', $r['out']);
        $this->assertNull($r['link']);
        $this->assertSame(0, Invitation::count());

        $again = $this->invite('busy@example.test', ['INVITE_REISSUE' => '1']);
        $this->assertNotNull($again['link']);
        $this->assertSame(1, Invitation::count());
    }

    public function test_it_refuses_bad_input_a_mailer_that_would_send_the_old_expiry_and_a_disabled_account_and_leaves_nothing_behind(): void
    {
        $this->assertStringContainsString('REFUSED: set INVITE_EMAIL', $this->invite('not-an-address')['out']);

        config(['mail.default' => 'smtp']);
        $this->assertStringContainsString('the mailer is not "log"', $this->invite()['out']);
        config(['mail.default' => 'log']);

        User::factory()->create(['email' => 'off@example.test', 'password' => null, 'disabled_at' => now()]);
        $r = $this->invite('off@example.test');
        $this->assertStringContainsString('FAILED and undone', $r['out']);
        $this->assertStringContainsString('is disabled', $r['out']);

        $r = $this->invite('rae@example.test', ['INVITE_ROLE' => 'wizard']);
        $this->assertStringContainsString('Unknown role', $r['out']);
        $this->assertSame(0, Invitation::count());
        $this->assertSame(0, User::where('email', 'rae@example.test')->count(), 'no half-made account');
    }

    public function test_the_showcase_copy_runs_after_the_account_exists_and_gives_the_new_user_the_items(): void
    {
        $author = User::factory()->create(['role' => 'owner'])->id;
        $source = $this->showcaseFor($author);
        $this->invite();

        putenv("SHOWCASE_SOURCE=$source");
        putenv('SHOWCASE_TARGET=rae@example.test');
        ob_start();
        try {
            include base_path('../scripts/demo/copy-showcase.php');
        } finally {
            $out = (string) ob_get_clean();
            putenv('SHOWCASE_SOURCE');
            putenv('SHOWCASE_TARGET');
        }

        $user = User::where('email', 'rae@example.test')->firstOrFail();
        $this->assertStringContainsString('studio items copied: 1', $out);
        $this->assertSame(1, DB::table('studio_items')->where('user_id', $user->id)->where('status', 'done')->count());
        $this->assertSame(0, DB::table('usage_events')->count());
    }

    public function test_a_link_past_its_signature_shows_the_generic_403_page_today_and_a_used_or_replaced_one_shows_the_clear_410_page(): void
    {
        // CHARACTERISATION, not a wish: an EXPIRED signature is stopped by the `signed` middleware before the controller, and the app shows its
        // generic "Not allowed" page. A used or replaced link, whose signature is still good, gets the clear "Link not valid" page.
        $link = $this->invite()['link'];
        Carbon::setTestNow(now()->addDays(8));

        $expired = $this->get($link);
        $expired->assertForbidden();
        $this->assertStringContainsString('Not allowed', $expired->getContent());

        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->invite();                                         // replaces the first link
        $replaced = $this->get($link);
        $replaced->assertStatus(410);
        $this->assertStringContainsString('invitation-invalid', $replaced->getContent());
    }
}
