<?php

namespace Tests\Feature\Studio;

use App\Ai\CostScope;
use App\Ai\Ledger;
use App\Models\Notebook;
use App\Models\StudioItem;
use App\Models\UsageEvent;
use App\Models\User;
use App\Studio\GenerateStudioItem;
use App\Studio\OrphanSweep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Argus (D-038, money): a worker killed mid-job and then reported by the queue through failed() must not leave its hold against the user for ever. */
class FailedJobReleasesHoldTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Notebook $notebook;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->notebook = Notebook::create(['user_id' => $this->user->id, 'title' => 'T']);
    }

    private function item(string $status = 'running'): StudioItem
    {
        return StudioItem::create(['notebook_id' => $this->notebook->id, 'user_id' => $this->user->id, 'kind' => 'audio', 'title' => 'A', 'status' => $status, 'step' => 'Recording voices']);
    }

    private function billed(int $micro, int $itemId): void
    {
        UsageEvent::create(['user_id' => $this->user->id, 'provider' => 'mistral', 'model' => 'm', 'kind' => 'chat', 'units' => [], 'cost_micro_usd' => $micro, 'key_owner' => 'app', 'studio_item_id' => $itemId]);
    }

    /** What a worker does before it is killed: reserve inside the item's scope through the REAL ledger, make a billed call, and never settle. */
    private function killedMidJob(StudioItem $item, int $hold, int $billed): void
    {
        CostScope::for($item->id, fn () => app(Ledger::class)->reserve($this->user, $hold));
        $this->billed($billed, $item->id);
    }

    public function test_a_killed_worker_then_failed_leaves_the_item_failed_the_hold_gone_and_only_the_billed_calls_spent(): void
    {
        $item = $this->item();
        $this->killedMidJob($item, 780_000, 104_600);
        $this->assertSame(104_600 + 780_000, app(Ledger::class)->spent($this->user), 'before: the hold counts against the user');

        (new GenerateStudioItem($item->id, 'media'))->failed(new \RuntimeException('killed'));

        $this->assertSame('failed', $item->fresh()->status);
        $this->assertSame('The job did not finish. Please try again.', $item->fresh()->error);
        $this->assertSame(0, DB::table('ledger_reservations')->count(), 'the hold is gone');
        $this->assertSame(104_600, app(Ledger::class)->spent($this->user), 'only the billed call is spent');
        $this->assertSame(0, UsageEvent::where('kind', 'unsettled')->count(), 'nothing charged in full on top');
    }

    public function test_it_is_idempotent_with_itself_and_with_the_orphan_sweep(): void
    {
        $item = $this->item();
        $this->killedMidJob($item, 780_000, 104_600);

        (new GenerateStudioItem($item->id, 'media'))->failed(new \RuntimeException);
        (new GenerateStudioItem($item->id, 'media'))->failed(new \RuntimeException);
        $swept = app(OrphanSweep::class)->run();

        $this->assertSame([], $swept['items'], 'the row is already failed: nothing for the sweep');
        $this->assertSame(104_600, app(Ledger::class)->spent($this->user));
    }

    public function test_the_sweep_having_run_first_then_failed_is_harmless(): void
    {
        $item = $this->item();
        $this->killedMidJob($item, 780_000, 104_600);
        DB::table('studio_items')->where('id', $item->id)->update(['updated_at' => now()->subDay()]);

        app(OrphanSweep::class)->run();
        (new GenerateStudioItem($item->id, 'media'))->failed(new \RuntimeException);

        $this->assertSame('failed', $item->fresh()->status);
        $this->assertSame(104_600, app(Ledger::class)->spent($this->user));
    }

    public function test_failed_frees_only_its_own_items_holds(): void
    {
        $dead = $this->item();
        $alive = $this->item();
        $this->killedMidJob($dead, 780_000, 1_000);
        $this->killedMidJob($alive, 500_000, 2_000);
        $chat = (int) DB::table('ledger_reservations')->insertGetId(['user_id' => $this->user->id, 'amount_micro_usd' => 333, 'created_at' => now(), 'studio_item_id' => null]);

        (new GenerateStudioItem($dead->id, 'media'))->failed(new \RuntimeException);

        $this->assertSame('running', $alive->fresh()->status);
        $this->assertSame(1, DB::table('ledger_reservations')->where('studio_item_id', $alive->id)->count(), 'the other item keeps its hold');
        $this->assertSame(1, DB::table('ledger_reservations')->where('id', $chat)->count(), 'a hold with no item is not touched');
        $this->assertSame(1_000 + 2_000 + 500_000 + 333, app(Ledger::class)->spent($this->user));
    }

    public function test_a_job_whose_item_is_gone_or_has_no_hold_fails_quietly(): void
    {
        (new GenerateStudioItem(999_999, 'media'))->failed(new \RuntimeException);

        $done = $this->item('done');
        (new GenerateStudioItem($done->id, 'media'))->failed(new \RuntimeException);

        $this->assertSame('done', $done->fresh()->status, 'a finished item is never turned into a failure');
        $this->assertSame(0, DB::table('ledger_reservations')->count());
    }
}
