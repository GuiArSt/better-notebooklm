<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('profile.edit'));

        $response->assertOk();
    }

    public function test_the_name_can_be_updated_and_the_email_is_left_alone()
    {
        $user = User::factory()->create();
        $email = $user->email;

        $this->actingAs($user)->patch(route('profile.update'), ['name' => 'Test User'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $user->refresh();
        $this->assertSame('Test User', $user->name);
        $this->assertSame($email, $user->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_a_request_that_tries_to_change_the_email_is_refused_and_changes_nothing()
    {
        $user = User::factory()->create();
        $email = $user->email;

        foreach (['new@example.com', 'NEW@Example.com', $email] as $try) {
            $this->actingAs($user)->from(route('profile.edit'))
                ->patch(route('profile.update'), ['name' => 'Renamed', 'email' => $try])
                ->assertSessionHasErrors(['email' => 'Your address is fixed for this demo.'])
                ->assertRedirect(route('profile.edit'));
        }

        $user->refresh();
        $this->assertSame($email, $user->email);
        $this->assertNotNull($user->email_verified_at, 'still verified');
        $this->assertNotSame('Renamed', $user->name, 'a refused request changes nothing');
    }

    public function test_the_profile_form_shows_the_email_disabled_and_never_sends_it()
    {
        // No browser test runner here, so read the page source: the email input is disabled, has no name (so Save cannot send it)
        // and the page says why.
        $tsx = (string) file_get_contents(base_path('resources/js/pages/settings/profile.tsx'));

        $this->assertSame(1, preg_match('/<Input\s[^>]*id="email"[^>]*>/s', $tsx, $m), 'the email input is there');
        $this->assertStringContainsString('disabled', $m[0]);
        $this->assertStringNotContainsString('name=', $m[0], 'a field without a name is not submitted');
        $this->assertStringContainsString('Your address is fixed for this demo.', $tsx);
        $this->assertSame(1, substr_count($tsx, 'name="name"'), 'the name field is the only named one');
        $this->assertStringNotContainsString('name="email"', $tsx);
    }

    public function test_the_name_is_required()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->from(route('profile.edit'))->patch(route('profile.update'), ['name' => ''])
            ->assertSessionHasErrors('name');
    }

    public function test_user_can_delete_their_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete(route('profile.destroy'), [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->fresh());
    }
}
