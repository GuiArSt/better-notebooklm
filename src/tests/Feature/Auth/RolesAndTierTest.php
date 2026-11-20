<?php

namespace Tests\Feature\Auth;

use App\Auth\GrantPro;
use App\Auth\RevokePro;
use App\Http\Middleware\RequireRole;
use App\Http\Middleware\ResolveAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RolesAndTierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth', ResolveAccess::class])->group(function () {
            Route::get('_ping', fn () => 'ok');
            Route::get('_owner-only', fn () => 'ok')->middleware(RequireRole::class.':owner');
            Route::get('_staff', fn () => 'ok')->middleware(RequireRole::class.':owner,reviewer');
        });
    }

    public function test_new_users_are_free_members(): void
    {
        $user = User::factory()->create()->fresh();

        $this->assertSame('member', $user->role);
        $this->assertSame('free', $user->tier);
        $this->assertNull($user->pro_until);
    }

    public function test_role_gates_refuse_the_wrong_role_and_guests(): void
    {
        $owner = User::factory()->withTwoFactor()->create(['role' => 'owner']);
        $reviewer = User::factory()->create(['role' => 'reviewer']);
        $member = User::factory()->create();

        $this->actingAs($owner)->get('_owner-only')->assertOk();
        $this->actingAs($reviewer)->get('_owner-only')->assertForbidden();
        $this->actingAs($member)->get('_owner-only')->assertForbidden();
        $this->actingAs($reviewer)->get('_staff')->assertOk();
        $this->actingAs($member)->get('_staff')->assertForbidden();

        auth()->logout();
        $this->get('_owner-only')->assertRedirect(route('login'));
    }

    public function test_grant_pro_sets_tier_and_a_30_day_period_and_does_not_stack(): void
    {
        $user = User::factory()->create();

        app(GrantPro::class)($user);
        $this->assertSame('pro', $user->fresh()->tier);
        $this->assertEqualsWithDelta(30 * 86400, now()->diffInSeconds($user->fresh()->pro_until), 5);

        $this->travel(10)->days();
        app(GrantPro::class)($user->fresh());
        $this->assertEqualsWithDelta(30 * 86400, now()->diffInSeconds($user->fresh()->pro_until), 5);
    }

    public function test_revoke_pro_returns_to_free(): void
    {
        $user = app(GrantPro::class)(User::factory()->create());

        app(RevokePro::class)($user);

        $this->assertSame('free', $user->fresh()->tier);
        $this->assertNull($user->fresh()->pro_until);
    }

    public function test_a_lapsed_pro_period_is_revoked_on_the_next_request(): void
    {
        $user = app(GrantPro::class)(User::factory()->create());
        $this->travel(31)->days();

        $this->actingAs($user->fresh())->get('_ping')->assertOk();
        $this->assertSame('free', $user->fresh()->getRawOriginal('tier'), 'the stored tier is corrected, not just read as free');
        $this->assertNull($user->fresh()->pro_until);
    }

    public function test_an_active_pro_period_stays_pro(): void
    {
        $user = app(GrantPro::class)(User::factory()->create());

        $this->actingAs($user->fresh())->get('_ping')->assertOk();
        $this->assertSame('pro', $user->fresh()->tier);
        $this->assertNotNull($user->fresh()->pro_until);
    }

    public function test_owner_and_reviewer_are_pro_by_role_and_a_member_without_a_purchase_is_free(): void
    {
        $this->assertSame('pro', User::factory()->create(['role' => 'reviewer'])->effectiveTier());
        $this->assertSame('pro', User::factory()->create(['role' => 'owner'])->effectiveTier());
        $this->assertSame('free', User::factory()->create(['role' => 'member'])->effectiveTier());

        $lapsed = User::factory()->create(['role' => 'member', 'tier' => 'pro', 'pro_until' => now()->subDay()]);
        $this->assertSame('free', $lapsed->effectiveTier());

        $paid = User::factory()->create(['role' => 'member', 'tier' => 'pro', 'pro_until' => now()->addDay()]);
        $this->assertSame('pro', $paid->effectiveTier());
    }

    public function test_pro_by_role_is_not_a_purchase(): void
    {
        $reviewer = User::factory()->create(['role' => 'reviewer']);
        $this->assertFalse($reviewer->hasProPurchase());

        // the pricing page offers a reviewer the purchase, not "Renew"
        $this->actingAs($reviewer)->get(route('pricing'))->assertInertia(fn ($page) => $page->where('tier', 'free'));
        $this->actingAs(User::factory()->create(['role' => 'member', 'tier' => 'pro', 'pro_until' => now()->addDay()]))
            ->get(route('pricing'))->assertInertia(fn ($page) => $page->where('tier', 'pro'));
    }

    public function test_a_lapsed_purchase_of_a_reviewer_is_still_revoked_in_the_column(): void
    {
        $reviewer = User::factory()->create(['role' => 'reviewer', 'tier' => 'pro', 'pro_until' => now()->subDay()]);

        $this->actingAs($reviewer)->get('_ping')->assertOk();

        $this->assertSame('free', $reviewer->fresh()->getRawOriginal('tier'));
        $this->assertSame('pro', $reviewer->fresh()->effectiveTier(), 'still pro by role');
    }
}
