<?php

namespace Tests\Feature;

use App\Ai\Exceptions\CapExceeded;
use App\Ai\Ledger;
use App\Models\UsageEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** L-1 (Argus): deleting an account must not make the money it spent disappear. Keep the money, drop the person. */
class LedgerSurvivesDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function spend(User $user, int $micro): void
    {
        UsageEvent::create(['user_id' => $user->id, 'provider' => 'mistral', 'model' => 'm', 'kind' => 'chat', 'units' => [], 'cost_micro_usd' => $micro, 'key_owner' => 'app']);
    }

    public function test_the_global_spend_is_unchanged_when_the_user_who_spent_it_is_deleted(): void
    {
        $gone = User::factory()->create(['role' => 'reviewer']);
        $stays = User::factory()->create();
        $this->spend($gone, 4_000_000);
        $this->spend($stays, 100_000);
        $ledger = app(Ledger::class);
        $before = $ledger->spent();

        $gone->delete();

        $this->assertSame(4_100_000, $before);
        $this->assertSame($before, $ledger->spent(), 'the global cap still counts the deleted user\'s spend');
        $this->assertSame(100_000, $ledger->spent($stays), 'another user\'s own spend is untouched');
        $this->assertSame(2, UsageEvent::count(), 'the rows stay');
        $this->assertNull(UsageEvent::where('cost_micro_usd', 4_000_000)->first()->user_id, 'the person is dropped, the money kept');
    }

    public function test_a_new_user_cannot_spend_what_a_deleted_user_already_used_of_the_global_cap(): void
    {
        config(['ledger.global_cap' => 4_050_000]);
        $gone = User::factory()->create();
        $this->spend($gone, 4_000_000);
        $gone->delete();

        $this->expectException(CapExceeded::class);
        app(Ledger::class)->reserve(User::factory()->create(['role' => 'reviewer']), 100_000);
    }

    public function test_a_hold_that_outlives_its_user_still_counts_and_is_swept_into_the_ledger(): void
    {
        $user = User::factory()->create();
        DB::table('ledger_reservations')->insert(['user_id' => $user->id, 'amount_micro_usd' => 777, 'created_at' => now()->subMinutes(30)]);
        $user->delete();
        $ledger = app(Ledger::class);

        $this->assertSame(777, $ledger->spent(), 'the hold is not forgotten');

        $ledger->sweep();

        $this->assertSame(0, DB::table('ledger_reservations')->count());
        $this->assertSame(777, $ledger->spent(), 'it became a spend with no person');
        $this->assertNull(UsageEvent::firstOrFail()->user_id);
    }

    public function test_the_ledger_keeps_ids_only_never_an_email(): void
    {
        $columns = DB::getSchemaBuilder()->getColumnListing('usage_events');

        foreach ($columns as $column) {
            $this->assertStringNotContainsStringIgnoringCase('mail', $column, "usage_events.$column");
        }
        $this->assertContains('user_id', $columns);
        $this->assertNotContains('email', DB::getSchemaBuilder()->getColumnListing('ledger_reservations'));
    }
}
