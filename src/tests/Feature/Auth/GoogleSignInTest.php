<?php

namespace Tests\Feature\Auth;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialUser;
use Mockery;
use Tests\TestCase;

class GoogleSignInTest extends TestCase
{
    use RefreshDatabase;

    private function googleReturns(string $email, bool $verified = true, string $id = 'g-123'): void
    {
        $social = (new SocialUser)->map(['id' => $id, 'name' => 'Gina Google', 'email' => $email]);
        $social->user = ['email_verified' => $verified];

        $driver = Mockery::mock();
        $driver->shouldReceive('user')->andReturn($social);
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
    }

    private function invitation(string $email, string $role = 'reviewer'): Invitation
    {
        return Invitation::create([
            'email' => $email, 'role' => $role, 'token_hash' => hash('sha256', 'x'), 'expires_at' => now()->addDay(),
        ]);
    }

    public function test_the_redirect_asks_google_for_openid_email_and_profile_only(): void
    {
        $response = $this->get(route('google.redirect'));

        $response->assertRedirectContains('accounts.google.com');
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->assertEqualsCanonicalizing(['openid', 'email', 'profile'], explode(' ', $q['scope']));
        $this->assertSame('select_account', $q['prompt'], 'Google always shows its account chooser');
    }

    public function test_an_invited_address_gets_an_account_with_the_invited_role(): void
    {
        $invitation = $this->invitation('gina@example.com', 'reviewer');
        $this->googleReturns('gina@example.com');

        $this->get(route('google.callback'))->assertRedirect(route('dashboard', absolute: false));

        $user = User::where('email', 'gina@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('reviewer', $user->role);
        $this->assertSame('g-123', $user->google_id);
        $this->assertNull($user->password);
        $this->assertNotNull($invitation->fresh()->accepted_at);
    }

    public function test_an_existing_account_signs_in_and_is_linked(): void
    {
        $user = User::factory()->create(['email' => 'gina@example.com']);
        $this->googleReturns('gina@example.com');

        $this->get(route('google.callback'))->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
        $this->assertSame('g-123', $user->fresh()->google_id);
    }

    public function test_an_uninvited_address_is_refused_and_nothing_is_created(): void
    {
        $this->googleReturns('stranger@example.com');

        $this->get(route('google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'This address has not been invited.']);

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_an_expired_or_used_invitation_does_not_admit_a_google_address(): void
    {
        Invitation::create(['email' => 'late@example.com', 'role' => 'member', 'token_hash' => 'x', 'expires_at' => now()->subHour()]);
        $used = $this->invitation('used@example.com');
        $used->forceFill(['accepted_at' => now()])->save();

        foreach (['late@example.com', 'used@example.com'] as $email) {
            $this->googleReturns($email);
            $this->get(route('google.callback'))->assertSessionHasErrors('email');
        }

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_an_unverified_google_email_is_refused(): void
    {
        $this->invitation('gina@example.com');
        $this->googleReturns('gina@example.com', verified: false);

        $this->get(route('google.callback'))->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_later_sign_ins_match_by_google_id_not_email(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com', 'google_id' => 'g-123']);
        $this->googleReturns('changed@example.com', id: 'g-123');

        $this->get(route('google.callback'))->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::count());
    }

    public function test_the_callback_is_rate_limited(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->get(route('google.callback'));
        }

        $this->get(route('google.callback'))->assertStatus(429);
    }

    public function test_a_google_failure_is_logged_by_class_only_and_the_user_is_told_to_retry(): void
    {
        $driver = Mockery::mock();
        $driver->shouldReceive('user')->andThrow(new \RuntimeException('secret detail from google'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
        $logged = [];
        Log::listen(function ($m) use (&$logged) {
            $logged[] = [$m->message, $m->context];
        });

        $this->get(route('google.callback'))->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $failure = collect($logged)->first(fn ($l) => $l[0] === 'Google callback failed');
        $this->assertNotNull($failure, 'the failure is logged');
        $this->assertSame(['error' => \RuntimeException::class], $failure[1]);
        $this->assertStringNotContainsString('secret detail', json_encode($logged));
    }
}
