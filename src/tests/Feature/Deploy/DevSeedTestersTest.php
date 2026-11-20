<?php

namespace Tests\Feature\Deploy;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** D-025: the dev stack has three test users the team's testers can sign in with; the command never runs in production. */
class DevSeedTestersTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> email => password, read from the console output */
    private function runSeed(): array
    {
        Artisan::call('dev:seed-testers');
        preg_match_all('/\|\s*(\S+@dev\.test)\s*\|\s*\S+\s*\|\s*(\S{16,})\s*\|/', Artisan::output(), $m, PREG_SET_ORDER);

        return collect($m)->mapWithKeys(fn ($row) => [$row[1] => $row[2]])->all();
    }

    public function test_it_creates_an_owner_a_reviewer_and_a_member_who_can_sign_in_with_the_printed_passwords(): void
    {
        $passwords = $this->runSeed();

        $this->assertEqualsCanonicalizing(['owner@dev.test', 'reviewer@dev.test', 'free@dev.test', 'pro@dev.test'], array_keys($passwords));
        $pro = User::where('email', 'pro@dev.test')->firstOrFail();
        $this->assertSame('pro', $pro->effectiveTier(), 'granted through GrantPro, as the Stripe flow does');
        $this->assertTrue($pro->pro_until->isFuture());
        $this->assertSame('owner', User::where('email', 'owner@dev.test')->value('role'));
        $this->assertSame('reviewer', User::where('email', 'reviewer@dev.test')->value('role'));
        $this->assertSame('member', User::where('email', 'free@dev.test')->value('role'));
        $this->assertSame('free', User::where('email', 'free@dev.test')->firstOrFail()->effectiveTier());

        foreach ($passwords as $email => $password) {
            $user = User::where('email', $email)->firstOrFail();
            $this->assertNotNull($user->email_verified_at, "$email is verified");
            $this->assertTrue(Hash::check($password, $user->password), "$email: the printed password is the real one");
            $this->post('/login', ['email' => $email, 'password' => $password])->assertRedirect();
            $this->assertAuthenticatedAs($user);
            auth()->logout();
        }
    }

    public function test_running_it_again_is_safe_and_makes_new_passwords(): void
    {
        $first = $this->runSeed();
        $second = $this->runSeed();

        $this->assertSame(4, User::where('email', 'like', '%@dev.test')->count());
        $this->assertNotSame($first['owner@dev.test'], $second['owner@dev.test']);
        $this->assertTrue(Hash::check($second['owner@dev.test'], User::where('email', 'owner@dev.test')->value('password')));
        $this->assertFalse(Hash::check($first['owner@dev.test'], User::where('email', 'owner@dev.test')->value('password')));
    }

    public function test_it_refuses_in_production_and_creates_nothing(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('dev:seed-testers')->expectsOutputToContain('production')->assertFailed();

        $this->assertSame(0, User::where('email', 'like', '%@dev.test')->count());
    }

    public function test_the_dev_stack_has_a_compose_project_of_its_own_not_the_local_database_project(): void
    {
        // compose.dev.yaml is the Mac's local development database (host port 5433, volume pgdata). A stack that shared its project name
        // would attach to that volume (found the hard way) and `down -v` would delete the developer's database.
        preg_match('/^name:\s*(\S+)/m', (string) file_get_contents(base_path('../deploy/compose.dev.yaml')), $local);
        preg_match('/^project="([^"]+)"/m', (string) file_get_contents(base_path('../deploy/dev-up.sh')), $stack);

        $this->assertNotEmpty($local[1]);
        $this->assertNotEmpty($stack[1]);
        $this->assertNotSame($local[1], $stack[1], 'the dev stack and the local dev database must be different compose projects');
    }
}
