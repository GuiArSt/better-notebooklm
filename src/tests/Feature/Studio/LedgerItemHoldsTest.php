<?php

namespace Tests\Feature\Studio;

use App\Ai\CostScope;
use App\Ai\Ledger;
use App\Models\UsageEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** A hold made for a studio item carries its id, so a killed job's hold is released with the item and never charged in full on top of real calls. */
class LedgerItemHoldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_hold_made_inside_an_item_scope_carries_the_items_id_and_one_outside_carries_none(): void
    {
        $user = User::factory()->create();
        $ledger = app(Ledger::class);

        $inside = CostScope::for(42, fn () => $ledger->reserve($user, 1_000));
        $outside = $ledger->reserve($user, 2_000);

        $this->assertSame(42, DB::table('ledger_reservations')->where('id', $inside->id)->value('studio_item_id'));
        $this->assertNull(DB::table('ledger_reservations')->where('id', $outside->id)->value('studio_item_id'));
    }

    public function test_the_sweep_leaves_an_items_hold_alone_and_still_charges_a_hold_with_no_item_as_before(): void
    {
        $user = User::factory()->create();
        $ledger = app(Ledger::class);
        $item = CostScope::for(42, fn () => $ledger->reserve($user, 1_000));
        $plain = $ledger->reserve($user, 2_000);
        DB::table('ledger_reservations')->update(['created_at' => now()->subHour()]);

        $ledger->sweep();

        $this->assertSame(0, DB::table('ledger_reservations')->where('id', $plain->id)->count(), 'the plain hold was swept');
        $this->assertSame(1, DB::table('ledger_reservations')->where('id', $item->id)->count(), 'the item hold stays for the orphan sweep');
        $this->assertSame(0, DB::table('ledger_reservations')->where('id', $plain->id)->count());
        $row = UsageEvent::where('kind', 'unsettled')->where('user_id', $user->id)->sole();
        $this->assertSame(2_000, $row->cost_micro_usd);
    }

    public function test_releasing_an_items_hold_frees_it_without_charging_anything(): void
    {
        $user = User::factory()->create();
        $ledger = app(Ledger::class);
        $hold = CostScope::for(42, fn () => $ledger->reserve($user, 5_000));

        $ledger->release($hold);

        $this->assertSame(0, DB::table('ledger_reservations')->where('user_id', $user->id)->count());
        $this->assertSame(0, UsageEvent::where('user_id', $user->id)->count());
        $this->assertSame(0, $ledger->spent($user));
    }
}
