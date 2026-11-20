<?php

namespace Tests\Feature;

use App\Ai\CostScope;
use App\Ai\Ledger;
use App\Models\Notebook;
use App\Models\StudioItem;
use App\Models\UsageEvent;
use App\Models\User;
use App\Studio\GenerateStudioItem;
use App\Studio\OrphanSweep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** D-038, in money: a killed job's hold must not stay against the user's budget, and must never be charged in full on top of the calls it really made. */
class OrphanSweepMoneyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Notebook $notebook;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-19 03:00:00');
        $this->user = User::factory()->create();
        $this->notebook = Notebook::create(['user_id' => $this->user->id, 'title' => 'T']);
    }

    private function item(int $secondsOld = 100_000): StudioItem
    {
        $item = StudioItem::create(['notebook_id' => $this->notebook->id, 'user_id' => $this->user->id, 'kind' => 'audio', 'title' => 'A', 'status' => 'running']);
        DB::table('studio_items')->where('id', $item->id)->update(['updated_at' => now()->subSeconds($secondsOld)]);

        return $item;
    }

    private function hold(int $micro, ?int $itemId, ?int $userId = null): int
    {
        return (int) DB::table('ledger_reservations')->insertGetId(['user_id' => $userId ?? $this->user->id, 'amount_micro_usd' => $micro, 'created_at' => now()->subHour(), 'studio_item_id' => $itemId]);
    }

    private function billed(int $micro, int $itemId): void
    {
        UsageEvent::create(['user_id' => $this->user->id, 'provider' => 'mistral', 'model' => 'm', 'kind' => 'chat', 'units' => [], 'cost_micro_usd' => $micro, 'key_owner' => 'app', 'studio_item_id' => $itemId]);
    }

    private function queue(int $itemId): void
    {
        DB::table('jobs')->insert([
            'queue' => 'media', 'attempts' => 1, 'reserved_at' => now()->timestamp, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp,
            'payload' => json_encode(['displayName' => GenerateStudioItem::class, 'data' => ['commandName' => GenerateStudioItem::class, 'command' => serialize(new GenerateStudioItem($itemId, 'media'))]]),
        ]);
    }

    public function test_a_killed_jobs_hold_disappears_and_the_users_spend_is_only_the_calls_it_really_made(): void
    {
        $item = $this->item();
        $this->billed(104_600, $item->id);   // the script, really billed
        $this->billed(131_800, $item->id);   // the first voice call, really billed
        $this->hold(780_000, $item->id);     // the whole episode's hold, never settled

        $this->assertSame(104_600 + 131_800 + 780_000, app(Ledger::class)->spent($this->user), 'before: the hold counts as spent');

        $done = app(OrphanSweep::class)->run();

        $this->assertSame([$item->id], $done['items']);
        $this->assertSame(0, DB::table('ledger_reservations')->count(), 'the hold is gone');
        $this->assertSame(104_600 + 131_800, app(Ledger::class)->spent($this->user), 'only the billed calls remain');
        $this->assertSame(0, UsageEvent::where('kind', 'unsettled')->count(), 'nothing was charged in full on top');
    }

    public function test_several_holds_of_the_one_item_are_all_released(): void
    {
        $item = $this->item();
        $this->hold(500_000, $item->id);
        $this->hold(40_000, $item->id);

        app(OrphanSweep::class)->run();

        $this->assertSame(0, DB::table('ledger_reservations')->where('studio_item_id', $item->id)->count());
        $this->assertSame(0, app(Ledger::class)->spent($this->user));
    }

    public function test_a_live_jobs_hold_is_left_alone_even_when_the_item_is_old(): void
    {
        $live = $this->item();
        $this->queue($live->id);
        $holdId = $this->hold(780_000, $live->id);

        $done = app(OrphanSweep::class)->run();

        $this->assertSame([], $done['items']);
        $this->assertSame(1, DB::table('ledger_reservations')->where('id', $holdId)->count());
        $this->assertSame(780_000, app(Ledger::class)->spent($this->user));
    }

    public function test_only_the_orphans_holds_go_not_another_items_nor_one_with_no_item_nor_another_users(): void
    {
        $orphan = $this->item();
        $live = $this->item();
        $this->queue($live->id);
        $other = User::factory()->create();
        $this->hold(111, $orphan->id);
        $liveHold = $this->hold(222, $live->id);
        $chatHold = $this->hold(333, null);                  // a chat answer in flight: belongs to no item
        $otherHold = $this->hold(444, null, $other->id);

        app(OrphanSweep::class)->run();

        $this->assertEqualsCanonicalizing([$liveHold, $chatHold, $otherHold], DB::table('ledger_reservations')->pluck('id')->map(fn ($i) => (int) $i)->all());
    }

    public function test_an_item_that_is_not_yet_an_orphan_keeps_its_hold(): void
    {
        $young = $this->item(60);
        $this->hold(780_000, $young->id);

        $done = app(OrphanSweep::class)->run();

        $this->assertSame([], $done['items']);
        $this->assertSame(780_000, app(Ledger::class)->spent($this->user));
    }

    public function test_end_to_end_a_hold_made_by_the_real_ledger_inside_a_job_is_kept_by_the_15_minute_sweep_and_released_by_the_orphan_sweep(): void
    {
        $item = $this->item();
        $ledger = app(Ledger::class);

        // The worker's job: a hold made through the real Ledger inside the item's scope, then the worker is killed (nothing settles it).
        CostScope::for($item->id, fn () => $ledger->reserve($this->user, 780_000));
        $this->billed(104_600, $item->id);
        DB::table('ledger_reservations')->update(['created_at' => now()->subHour()]);
        $this->assertSame(1, DB::table('ledger_reservations')->where('studio_item_id', $item->id)->count(), 'the real reserve() tagged the hold');

        // The old sweep (15 minutes) must NOT charge it in full ...
        $this->assertSame(0, $ledger->sweep(15));
        $this->assertSame(0, UsageEvent::where('kind', 'unsettled')->count());
        $this->assertSame(104_600 + 780_000, $ledger->spent($this->user), 'still held, not yet charged');

        // ... the orphan sweep releases it, and only the billed call is left.
        app(OrphanSweep::class)->run();

        $this->assertSame(0, DB::table('ledger_reservations')->count());
        $this->assertSame(104_600, $ledger->spent($this->user));
        $this->assertSame(0, UsageEvent::where('kind', 'unsettled')->count());
    }
}
