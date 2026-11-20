<?php

namespace Tests\Feature\Auth;

use App\Models\Invitation;
use App\Models\Notebook;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** P-1: deleting an account deletes everything, for Google users too. */
class DeleteAccountTest extends TestCase
{
    use RefreshDatabase;

    private function googleUserWithFiles(): array
    {
        Storage::fake('local');
        $user = User::factory()->create(['email' => 'gina@example.com', 'password' => null, 'google_id' => 'g-1']);
        $notebook = Notebook::create(['user_id' => $user->id, 'title' => 'Mine']);
        Storage::disk('local')->put("sources/{$notebook->id}/a.pdf", 'pdf');
        Storage::disk('local')->put("studio/{$notebook->id}/1.mp3", 'mp3');
        Invitation::create(['email' => 'gina@example.com', 'role' => 'member', 'token_hash' => 'x', 'expires_at' => now()->addDay(), 'accepted_at' => now()]);
        Invitation::create(['email' => 'other@example.com', 'role' => 'member', 'token_hash' => 'y', 'expires_at' => now()->addDay()]);

        return [$user, $notebook];
    }

    public function test_a_google_user_deletes_the_account_by_typing_their_email(): void
    {
        [$user, $notebook] = $this->googleUserWithFiles();

        $this->actingAs($user)->delete(route('profile.destroy'), ['email' => 'GINA@example.com'])
            ->assertSessionHasNoErrors()->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull(User::find($user->id));
        $this->assertSame(0, Notebook::where('user_id', $user->id)->count());
        Storage::disk('local')->assertMissing("sources/{$notebook->id}/a.pdf");
        Storage::disk('local')->assertMissing("studio/{$notebook->id}/1.mp3");
        $this->assertSame(0, Invitation::where('email', 'gina@example.com')->count());
        $this->assertSame(1, Invitation::where('email', 'other@example.com')->count(), 'only this user\'s invitations go');
    }

    public function test_a_wrong_email_does_not_delete_a_google_account(): void
    {
        [$user, $notebook] = $this->googleUserWithFiles();

        $this->actingAs($user)->from(route('profile.edit'))->delete(route('profile.destroy'), ['email' => 'someone@else.com'])
            ->assertSessionHasErrors('email');

        $this->assertNotNull($user->fresh());
        Storage::disk('local')->assertExists("sources/{$notebook->id}/a.pdf");
    }

    public function test_the_password_is_not_accepted_in_place_of_the_email_for_a_google_user(): void
    {
        [$user] = $this->googleUserWithFiles();

        $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password'])->assertSessionHasErrors('email');

        $this->assertNotNull($user->fresh());
    }

    public function test_a_password_user_still_needs_the_password_and_loses_their_files(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $notebook = Notebook::create(['user_id' => $user->id, 'title' => 'Mine']);
        Storage::disk('local')->put("sources/{$notebook->id}/a.pdf", 'pdf');

        $this->actingAs($user)->delete(route('profile.destroy'), ['email' => $user->email])->assertSessionHasErrors('password');
        Storage::disk('local')->assertExists("sources/{$notebook->id}/a.pdf");

        $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password'])->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing("sources/{$notebook->id}/a.pdf");
        $this->assertNull($user->fresh());
    }

    public function test_the_database_cascade_leaves_no_rows_of_the_user(): void
    {
        [$user, $notebook] = $this->googleUserWithFiles();
        Source::create(['notebook_id' => $notebook->id, 'kind' => 'text', 'title' => 'S', 'status' => 'ready']);

        $this->actingAs($user)->delete(route('profile.destroy'), ['email' => 'gina@example.com']);

        $this->assertSame(0, DB::table('sources')->where('notebook_id', $notebook->id)->count());
        $this->assertSame(0, DB::table('notebooks')->where('id', $notebook->id)->count());
    }

    public function test_the_profile_page_says_whether_the_user_has_a_password(): void
    {
        $google = User::factory()->create(['password' => null]);

        $this->actingAs($google)->get(route('profile.edit'))->assertInertia(fn ($page) => $page->where('hasPassword', false));
        $this->actingAs(User::factory()->create())->get(route('profile.edit'))->assertInertia(fn ($page) => $page->where('hasPassword', true));
    }

    public function test_the_only_owner_cannot_delete_their_own_account_in_the_app(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['role' => 'owner']);

        $this->actingAs($owner)->from(route('profile.edit'))->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasErrors('password');

        $this->assertNotNull($owner->fresh());
        $this->assertAuthenticatedAs($owner);
    }

    public function test_an_only_owner_who_signed_in_with_google_is_refused_the_same_way(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'password' => null, 'email' => 'boss@example.com']);

        $this->actingAs($owner)->delete(route('profile.destroy'), ['email' => 'boss@example.com'])->assertSessionHasErrors('email');

        $this->assertNotNull($owner->fresh());
    }

    public function test_an_owner_may_delete_their_own_account_when_another_owner_exists(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['role' => 'owner']);
        User::factory()->create(['role' => 'owner']);
        $notebook = Notebook::create(['user_id' => $owner->id, 'title' => 'Mine']);
        Storage::disk('local')->put("sources/{$notebook->id}/a.pdf", 'pdf');

        $this->actingAs($owner)->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasNoErrors()->assertRedirect('/');

        $this->assertNull(User::find($owner->id));
        Storage::disk('local')->assertMissing("sources/{$notebook->id}/a.pdf");
    }

    public function test_a_notebook_icon_file_is_gone_after_the_account_is_deleted(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $notebook = Notebook::create(['user_id' => $user->id, 'title' => 'Mine']);
        $notebook->forceFill(['icon_path' => "icons/{$user->id}/{$notebook->id}.webp"])->save();
        Storage::disk('local')->put($notebook->icon_path, 'webp');

        $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password'])->assertSessionHasNoErrors();

        Storage::disk('local')->assertMissing("icons/{$user->id}/{$notebook->id}.webp");
        $this->assertNull(User::find($user->id));
    }
}
