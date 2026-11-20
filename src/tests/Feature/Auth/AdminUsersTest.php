<?php

namespace Tests\Feature\Auth;

use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminUsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_sees_users_and_invitations(): void
    {
        $owner = User::factory()->withTwoFactor()->create(['role' => 'owner']);
        Invitation::create(['email' => 'p@example.com', 'role' => 'member', 'token_hash' => 'x', 'expires_at' => now()->addDay()]);

        $this->actingAs($owner)->get(route('admin.users'))->assertInertia(fn (Assert $page) => $page
            ->component('admin/users')
            ->has('users', 1)
            ->where('invitations.0.status', 'pending'));
    }

    public function test_reviewers_members_and_guests_are_refused(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'reviewer']))->get(route('admin.users'))->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'member']))->get(route('admin.users'))->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'member']))
            ->post(route('admin.invitations.store'), ['email' => 'a@example.com', 'role' => 'owner'])->assertForbidden();

        auth()->logout();
        $this->get(route('admin.users'))->assertRedirect(route('login'));
    }

    public function test_the_owner_can_invite_and_a_bad_role_is_refused(): void
    {
        Mail::fake();
        $owner = User::factory()->withTwoFactor()->create(['role' => 'owner']);

        $this->actingAs($owner)->post(route('admin.invitations.store'), ['email' => 'n@example.com', 'role' => 'reviewer'])
            ->assertSessionHas('status');
        Mail::assertSent(InvitationMail::class);
        $this->assertSame($owner->id, Invitation::first()->invited_by);

        $this->actingAs($owner)->post(route('admin.invitations.store'), ['email' => 'm@example.com', 'role' => 'god'])
            ->assertSessionHasErrors('role');
        $this->assertSame(1, Invitation::count());
    }
}
