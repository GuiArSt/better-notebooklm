<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

/** D-024: two-factor is optional for every role. It stays offered; an account that turned it on is still challenged. */
class OwnerTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_owner_without_two_factor_signs_in_and_lands_on_the_greeting(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);

        $this->post(route('login.store'), ['email' => $owner->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($owner);

        $this->get(route('dashboard'))->assertRedirect(route('greeting.show'));
        $this->get(route('greeting.show'))->assertOk();
        $this->get(route('admin.users'))->assertOk();
    }

    public function test_an_owner_with_two_factor_is_still_challenged(): void
    {
        $owner = User::factory()->withTwoFactor()->create(['role' => 'owner']);

        $this->post(route('login.store'), ['email' => $owner->email, 'password' => 'password'])
            ->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
    }

    public function test_two_factor_is_still_offered_in_the_security_settings(): void
    {
        $this->assertTrue(Features::enabled(Features::twoFactorAuthentication()));

        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))->assertOk();
    }

    public function test_reviewers_and_members_are_not_forced_to_use_two_factor(): void
    {
        foreach (['reviewer', 'member'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get(route('notebooks.index'))->assertOk();
        }
    }
}
