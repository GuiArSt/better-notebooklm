<?php

namespace Tests\Feature\Auth;

use App\Models\Invitation;
use App\Models\Notebook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialUser;
use Tests\TestCase;

/** The owner's user tools (`scripts/users.sh` runs these). No command takes a password; every write path is run twice. */
class UsersCommandsTest extends TestCase
{
    use RefreshDatabase;

    private function link(string $output): string
    {
        $this->assertSame(1, preg_match('#https?://\S+/invitation/\d+\?\S+#', $output, $m), 'a link is printed');

        return $m[0];
    }

    private function run2(string $command, array $args): array
    {
        return [Artisan::call($command, $args), Artisan::call($command, $args)];
    }

    public function test_list_shows_email_role_tier_pro_until_status_and_last_seen(): void
    {
        $a = User::factory()->create(['email' => 'a@example.test', 'role' => 'owner']);
        $b = User::factory()->create(['email' => 'b@example.test', 'role' => 'reviewer', 'tier' => 'pro', 'pro_until' => now()->addDays(3), 'disabled_at' => now()]);
        DB::table('sessions')->insert(['id' => 's1', 'user_id' => $a->id, 'payload' => '', 'last_activity' => strtotime('2026-11-17 10:00:00')]);

        Artisan::call('users:list');
        $out = Artisan::output();

        foreach (['a@example.test', 'owner', 'b@example.test', 'disabled', 'active', 'never', '2026-11-17', $b->pro_until->toDateTimeString()] as $needle) {
            $this->assertStringContainsString($needle, $out);
        }
        $this->assertStringNotContainsString($a->password, $out);
    }

    public function test_list_json_is_machine_output(): void
    {
        User::factory()->create(['email' => 'a@example.test', 'role' => 'owner']);

        Artisan::call('users:list', ['--json' => true]);
        $rows = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('a@example.test', $rows[0]['email']);
        $this->assertSame(['email', 'role', 'tier', 'pro_until', 'status', 'last_seen'], array_keys($rows[0]));
        $this->assertArrayNotHasKey('password', $rows[0]);
    }

    public function test_invite_prints_a_working_link_and_inviting_twice_makes_one_pending_invitation(): void
    {
        [$a, $b] = $this->run2('users:invite', ['email' => 'new@example.test', 'role' => 'reviewer']);
        $this->assertSame([0, 0], [$a, $b]);

        $this->assertSame(1, Invitation::where('email', 'new@example.test')->pending()->count());
        Artisan::call('users:invite', ['email' => 'new@example.test', 'role' => 'reviewer', '--json' => true]);
        $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->get($json['link'])->assertOk();

        $this->post($json['link'], ['name' => 'N', 'password' => 'a-long-enough-passphrase', 'password_confirmation' => 'a-long-enough-passphrase']);
        $this->assertSame(1, User::where('email', 'new@example.test')->count(), 'two invitations, one user');
    }

    public function test_invite_re_invites_an_existing_user_and_refuses_a_bad_role(): void
    {
        User::factory()->create(['email' => 'old@example.test']);

        $this->assertSame(0, Artisan::call('users:invite', ['email' => 'old@example.test', 'role' => 'member']));
        $this->get($this->link(Artisan::output()))->assertOk();

        $this->assertSame(1, Artisan::call('users:invite', ['email' => 'x@example.test', 'role' => 'god']));
        $this->assertSame(0, Invitation::where('email', 'x@example.test')->count());
    }

    public function test_link_gives_an_existing_user_a_fresh_link_takes_no_password_and_can_run_twice(): void
    {
        User::factory()->create(['email' => 'lost@example.test', 'role' => 'reviewer']);
        Artisan::call('users:link', ['email' => 'lost@example.test']);
        $first = $this->link(Artisan::output());
        Artisan::call('users:link', ['email' => 'lost@example.test']);
        $second = $this->link(Artisan::output());

        $this->get($first)->assertStatus(410);   // replaced
        $this->get($second)->assertOk();
        $this->assertSame(1, Invitation::where('email', 'lost@example.test')->pending()->count());

        $this->post($second, ['password' => 'a-brand-new-passphrase', 'password_confirmation' => 'a-brand-new-passphrase'])->assertRedirect();
        $this->assertSame('reviewer', User::where('email', 'lost@example.test')->value('role'), 'the role is kept');

        $definition = Artisan::all()['users:link']->getDefinition();
        $this->assertArrayNotHasKey('password', $definition->getArguments());
        $this->assertArrayNotHasKey('password', $definition->getOptions());
    }

    public function test_link_refuses_an_unknown_user_with_one_line_naming_the_email(): void
    {
        $this->assertSame(1, Artisan::call('users:link', ['email' => 'nobody@example.test']));
        $this->assertStringContainsString('No user nobody@example.test', Artisan::output());
        $this->assertSame(0, Invitation::count());
    }

    public function test_role_changes_the_role_idempotently_and_refuses_a_bad_one(): void
    {
        User::factory()->create(['email' => 'r@example.test']);

        $this->assertSame([0, 0], $this->run2('users:role', ['email' => 'r@example.test', 'role' => 'reviewer']));
        $this->assertSame('reviewer', User::where('email', 'r@example.test')->value('role'));

        $this->assertSame(1, Artisan::call('users:role', ['email' => 'r@example.test', 'role' => 'god']));
        $this->assertSame(1, Artisan::call('users:role', ['email' => 'ghost@example.test', 'role' => 'member']));
    }

    public function test_taking_owner_away_needs_the_email_typed_back(): void
    {
        User::factory()->create(['email' => 'o@example.test', 'role' => 'owner']);
        User::factory()->create(['email' => 'o2@example.test', 'role' => 'owner']);   // not the only owner

        $this->artisan('users:role', ['email' => 'o@example.test', 'role' => 'member'])
            ->expectsQuestion('Owner status of o@example.test changes. Type the email to confirm', 'wrong@example.test')->assertFailed();
        $this->assertSame('owner', User::where('email', 'o@example.test')->value('role'));

        $this->assertSame(1, Artisan::call('users:role', ['email' => 'o@example.test', 'role' => 'member', '--no-interaction' => true]));
        $this->assertSame('owner', User::where('email', 'o@example.test')->value('role'));

        $this->assertSame(0, Artisan::call('users:role', ['email' => 'o@example.test', 'role' => 'member', '--confirm' => 'o@example.test']));
        $this->assertSame('member', User::where('email', 'o@example.test')->value('role'));
    }

    public function test_the_only_owner_cannot_be_demoted(): void
    {
        $only = User::factory()->create(['email' => 'boss@example.test', 'role' => 'owner']);

        $this->assertSame(1, Artisan::call('users:role', ['email' => 'boss@example.test', 'role' => 'member', '--confirm' => 'boss@example.test']));
        $this->assertStringContainsString('only owner', Artisan::output());
        $this->assertSame('owner', $only->fresh()->role);

        // keeping the role is fine, and so is demoting once another owner exists
        $this->assertSame(0, Artisan::call('users:role', ['email' => 'boss@example.test', 'role' => 'owner']));
        User::factory()->create(['email' => 'boss2@example.test', 'role' => 'owner']);
        $this->assertSame(0, Artisan::call('users:role', ['email' => 'boss@example.test', 'role' => 'member', '--confirm' => 'boss@example.test']));
    }

    public function test_making_someone_owner_also_needs_the_email_typed_back(): void
    {
        User::factory()->create(['email' => 'm@example.test', 'role' => 'member']);

        $this->assertSame(1, Artisan::call('users:role', ['email' => 'm@example.test', 'role' => 'owner', '--no-interaction' => true]));
        $this->assertSame('member', User::where('email', 'm@example.test')->value('role'));

        $this->assertSame(0, Artisan::call('users:role', ['email' => 'm@example.test', 'role' => 'owner', '--confirm' => 'm@example.test']));
        $this->assertSame('owner', User::where('email', 'm@example.test')->value('role'));
        // member <-> reviewer needs no confirmation
        User::factory()->create(['email' => 'v@example.test']);
        $this->assertSame(0, Artisan::call('users:role', ['email' => 'v@example.test', 'role' => 'reviewer', '--no-interaction' => true]));
    }

    public function test_pro_on_and_off_go_through_grant_and_revoke_and_do_not_stack(): void
    {
        $u = User::factory()->create(['email' => 'p@example.test']);

        $this->assertSame([0, 0], $this->run2('users:pro', ['email' => 'p@example.test', 'state' => 'on']));
        $this->assertSame('pro', $u->fresh()->tier);
        $this->assertEqualsWithDelta(30 * 86400, now()->diffInSeconds($u->fresh()->pro_until), 5);

        $this->assertSame([0, 0], $this->run2('users:pro', ['email' => 'p@example.test', 'state' => 'off']));
        $this->assertSame('free', $u->fresh()->tier);
        $this->assertNull($u->fresh()->pro_until);

        $this->assertSame(1, Artisan::call('users:pro', ['email' => 'p@example.test', 'state' => 'maybe']));
    }

    public function test_disable_blocks_every_way_in_keeps_the_data_and_can_be_undone(): void
    {
        $u = User::factory()->create(['email' => 'x@example.test', 'google_id' => 'g-9']);
        $nb = Notebook::create(['user_id' => $u->id, 'title' => 'Mine']);
        DB::table('sessions')->insert(['id' => 'old', 'user_id' => $u->id, 'payload' => '', 'last_activity' => time()]);

        $this->assertSame([0, 0], $this->run2('users:disable', ['email' => 'x@example.test']));

        $this->assertNotNull($u->fresh()->disabled_at);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $u->id)->count(), 'sessions end');
        $this->assertNotNull(Notebook::find($nb->id), 'data kept');

        // password
        $this->post(route('login.store'), ['email' => 'x@example.test', 'password' => 'password'])->assertSessionHasErrors();
        $this->assertGuest();
        // an already signed-in session / remember-me
        $this->actingAs($u->fresh())->get(route('notebooks.index'))->assertRedirect(route('login'));
        $this->assertGuest();
        // google
        $social = (new SocialUser)->map(['id' => 'g-9', 'name' => 'X', 'email' => 'x@example.test']);
        $social->user = ['email_verified' => true];
        $driver = \Mockery::mock();
        $driver->shouldReceive('user')->andReturn($social);
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
        $this->get(route('google.callback'))->assertSessionHasErrors('email');
        $this->assertGuest();
        // a link is refused until the user is enabled
        $this->assertSame(1, Artisan::call('users:link', ['email' => 'x@example.test']));

        $this->assertSame([0, 0], $this->run2('users:disable', ['email' => 'x@example.test', '--undo' => true]));
        $this->assertNull($u->fresh()->disabled_at);
        $this->post(route('login.store'), ['email' => 'x@example.test', 'password' => 'password'])->assertRedirect();
        $this->assertAuthenticatedAs($u);
    }

    public function test_delete_needs_the_email_typed_back_then_removes_the_user_and_their_data(): void
    {
        Storage::fake('local');
        $u = User::factory()->create(['email' => 'd@example.test', 'disabled_at' => now()]);
        $nb = Notebook::create(['user_id' => $u->id, 'title' => 'N']);
        Storage::disk('local')->put("sources/{$nb->id}/a.pdf", 'pdf');

        $this->artisan('users:delete', ['email' => 'd@example.test'])
            ->expectsQuestion('This deletes d@example.test (member) and all their data for good. Type the email to confirm', 'nope')->assertFailed();
        $this->assertNotNull($u->fresh());
        $this->assertSame(1, Artisan::call('users:delete', ['email' => 'd@example.test', '--no-interaction' => true]), 'no terminal, no confirmation: nothing deleted');
        $this->assertNotNull($u->fresh());

        $this->assertSame([0, 0], $this->run2('users:delete', ['email' => 'd@example.test', '--confirm' => 'd@example.test']));   // the second run: already gone
        $this->assertNull(User::find($u->id));
        Storage::disk('local')->assertMissing("sources/{$nb->id}/a.pdf");
    }

    public function test_the_only_active_owner_cannot_be_disabled(): void
    {
        $only = User::factory()->create(['email' => 'boss@example.test', 'role' => 'owner']);

        $this->assertSame(1, Artisan::call('users:disable', ['email' => 'boss@example.test']));
        $this->assertStringContainsString('only owner', Artisan::output());
        $this->assertNull($only->fresh()->disabled_at);

        // a second owner who is itself disabled does not count
        User::factory()->create(['email' => 'boss2@example.test', 'role' => 'owner', 'disabled_at' => now()]);
        $this->assertSame(1, Artisan::call('users:disable', ['email' => 'boss@example.test']));

        User::factory()->create(['email' => 'boss3@example.test', 'role' => 'owner']);
        $this->assertSame([0, 0], $this->run2('users:disable', ['email' => 'boss@example.test']));
        $this->assertNotNull($only->fresh()->disabled_at);
        // undoing is always allowed
        $this->assertSame(0, Artisan::call('users:disable', ['email' => 'boss@example.test', '--undo' => true]));
    }

    public function test_delete_refuses_an_active_user_until_they_are_disabled(): void
    {
        $u = User::factory()->create(['email' => 'act@example.test']);

        $this->assertSame(1, Artisan::call('users:delete', ['email' => 'act@example.test', '--confirm' => 'act@example.test']));
        $this->assertStringContainsString('users:disable act@example.test', Artisan::output());
        $this->assertNotNull($u->fresh());

        Artisan::call('users:disable', ['email' => 'act@example.test']);
        $this->assertSame(0, Artisan::call('users:delete', ['email' => 'act@example.test', '--confirm' => 'act@example.test']));
        $this->assertNull(User::find($u->id));
    }

    public function test_delete_never_removes_the_only_owner(): void
    {
        $only = User::factory()->create(['email' => 'boss@example.test', 'role' => 'owner', 'disabled_at' => now()]);

        $this->assertSame(1, Artisan::call('users:delete', ['email' => 'boss@example.test', '--confirm' => 'boss@example.test']));
        $this->assertStringContainsString('only owner', Artisan::output());
        $this->assertNotNull($only->fresh());

        // with a second owner it is allowed
        User::factory()->create(['email' => 'boss2@example.test', 'role' => 'owner']);
        $this->assertSame(0, Artisan::call('users:delete', ['email' => 'boss@example.test', '--confirm' => 'boss@example.test']));
        $this->assertNull(User::find($only->id));
    }

    public function test_no_command_prints_a_password_hash_or_the_app_key(): void
    {
        $u = User::factory()->create(['email' => 'q@example.test']);

        foreach ([['users:list', []], ['users:link', ['email' => 'q@example.test']], ['users:pro', ['email' => 'q@example.test', 'state' => 'on']]] as [$cmd, $args]) {
            Artisan::call($cmd, $args);
            $this->assertStringNotContainsString($u->password, Artisan::output());
            $this->assertStringNotContainsString(config('app.key'), Artisan::output());
        }
    }

    public function test_the_message_for_a_disabled_user_names_a_command_that_exists(): void
    {
        User::factory()->create(['email' => 'off@example.test', 'disabled_at' => now()]);

        $this->assertSame(1, Artisan::call('users:link', ['email' => 'off@example.test']));
        $out = Artisan::output();

        $this->assertStringContainsString('users:disable off@example.test --undo', $out);
        $this->assertStringNotContainsString('users:enable', $out);
        $this->assertArrayHasKey('users:disable', Artisan::all());
    }
}
