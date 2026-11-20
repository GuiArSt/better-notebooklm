<?php

namespace Tests\Feature;

use App\Icons\GenerateNotebookIcon;
use App\Models\Notebook;
use App\Models\NotebookOverview;
use App\Models\User;
use App\Sources\BuildOverview;
use App\Studio\OrphanSweep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Gap 6 (and 4): a notebook's icon or overview left queued, running or building by a lost job must end failed, and a live job must be left alone. */
class IconSweepTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-19 12:00:00');
        $this->user = User::factory()->create();
    }

    private function limit(string $job): int
    {
        return (new \ReflectionClass($job))->getProperty('timeout')->getDefaultValue() + OrphanSweep::MARGIN_SECONDS;
    }

    private function notebook(?string $icon, int $secondsOld): Notebook
    {
        $notebook = Notebook::create(['user_id' => $this->user->id, 'title' => 'Book '.uniqid()]);
        // icon_status is not mass assignable (only the icon job and the request flip it), so it is written straight.
        DB::table('notebooks')->where('id', $notebook->id)->update(['icon_status' => $icon, 'updated_at' => now()->subSeconds($secondsOld)]);

        return $notebook;
    }

    private function queue(object $job, ?int $reservedAt = null): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default', 'attempts' => $reservedAt ? 1 : 0, 'reserved_at' => $reservedAt, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp,
            'payload' => json_encode(['displayName' => $job::class, 'data' => ['commandName' => $job::class, 'command' => serialize($job)]]),
        ]);
    }

    public function test_an_icon_stuck_queued_or_running_with_no_job_ends_failed_and_the_emoji_stays(): void
    {
        $limit = $this->limit(GenerateNotebookIcon::class);
        $queued = $this->notebook('queued', $limit + 5);
        $running = $this->notebook('running', $limit + 600);

        $done = app(OrphanSweep::class)->run();

        $this->assertEqualsCanonicalizing([$queued->id, $running->id], $done['icons']);
        $this->assertSame('failed', $queued->fresh()->icon_status);
        $this->assertSame('Interrupted', $queued->fresh()->icon_error);
        $this->assertNull($queued->fresh()->icon_path, 'nothing else about the notebook changes');
    }

    public function test_a_live_icon_job_waiting_or_reserved_is_left_alone_however_old(): void
    {
        $waiting = $this->notebook('queued', 100_000);
        $reserved = $this->notebook('running', 100_000);
        $this->queue(new GenerateNotebookIcon($waiting->id));
        $this->queue(new GenerateNotebookIcon($reserved->id), now()->timestamp);

        $done = app(OrphanSweep::class)->run();

        $this->assertSame([], $done['icons']);
        $this->assertSame('queued', $waiting->fresh()->icon_status);
        $this->assertSame('running', $reserved->fresh()->icon_status);
    }

    public function test_the_boundary_young_icons_and_every_other_state_are_left_alone(): void
    {
        $limit = $this->limit(GenerateNotebookIcon::class);
        $at = $this->notebook('running', $limit);
        $past = $this->notebook('running', $limit + 1);
        $recent = $this->notebook('queued', 30);
        $done = $this->notebook('done', 100_000);
        $failed = $this->notebook('failed', 100_000);
        $none = $this->notebook(null, 100_000);

        $result = app(OrphanSweep::class)->run();

        $this->assertSame([$past->id], $result['icons']);
        foreach ([[$at, 'running'], [$recent, 'queued'], [$done, 'done'], [$failed, 'failed'], [$none, null]] as [$notebook, $status]) {
            $this->assertSame($status, $notebook->fresh()->icon_status);
        }
    }

    public function test_an_icon_job_is_matched_by_its_own_notebook_not_by_a_job_for_another(): void
    {
        $orphan = $this->notebook('queued', 100_000);
        $other = $this->notebook('queued', 100_000);
        $this->queue(new GenerateNotebookIcon($other->id));

        $done = app(OrphanSweep::class)->run();

        $this->assertSame([$orphan->id], $done['icons']);
    }

    public function test_an_overview_stuck_building_with_no_job_ends_failed_and_a_live_one_is_left(): void
    {
        $limit = $this->limit(BuildOverview::class);
        $lost = $this->notebook(null, 10);
        $live = $this->notebook(null, 10);
        $young = $this->notebook(null, 10);
        $ready = $this->notebook(null, 10);
        foreach ([$lost, $live, $young] as $n) {
            NotebookOverview::create(['notebook_id' => $n->id, 'status' => 'building']);
        }
        NotebookOverview::create(['notebook_id' => $ready->id, 'summary' => 's', 'questions' => ['q'], 'status' => 'ready']);
        DB::table('notebook_overviews')->whereIn('notebook_id', [$lost->id, $live->id])->update(['updated_at' => now()->subSeconds($limit + 1)]);
        DB::table('notebook_overviews')->where('notebook_id', $ready->id)->update(['updated_at' => now()->subDays(3)]);
        $this->queue(new BuildOverview($live->id));

        $done = app(OrphanSweep::class)->run();

        $this->assertSame([$lost->id], $done['overviews']);
        $this->assertSame('failed', NotebookOverview::where('notebook_id', $lost->id)->value('status'));
        $this->assertSame('building', NotebookOverview::where('notebook_id', $live->id)->value('status'));
        $this->assertSame('building', NotebookOverview::where('notebook_id', $young->id)->value('status'));
        $this->assertSame('ready', NotebookOverview::where('notebook_id', $ready->id)->value('status'));
    }

    public function test_the_command_reports_icons_and_overviews_too(): void
    {
        $this->notebook('running', 100_000);

        $this->artisan('orphans:sweep')->expectsOutputToContain('1 notebook icon(s) and 0 overview(s) marked failed.')->assertSuccessful();
    }
}
