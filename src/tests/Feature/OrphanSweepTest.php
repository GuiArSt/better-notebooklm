<?php

namespace Tests\Feature;

use App\Models\Notebook;
use App\Models\Source;
use App\Models\StudioItem;
use App\Models\User;
use App\Sources\EmbedSource;
use App\Studio\GenerateStudioItem;
use App\Studio\OrphanSweep;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/** D-038: a row left pending or running with no job behind it must end failed, with a plain sentence, and a live job must never be touched. */
class OrphanSweepTest extends TestCase
{
    use RefreshDatabase;

    private Notebook $notebook;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-19 03:00:00');
        $this->userId = User::factory()->create()->id;
        $this->notebook = Notebook::create(['user_id' => $this->userId, 'title' => 'T']);
    }

    private function item(string $status, int $secondsOld): StudioItem
    {
        $item = StudioItem::create(['notebook_id' => $this->notebook->id, 'user_id' => $this->userId, 'kind' => 'audio', 'title' => 'A', 'status' => $status, 'step' => 'Recording voices']);
        DB::table('studio_items')->where('id', $item->id)->update(['updated_at' => now()->subSeconds($secondsOld)]);

        return $item;
    }

    private function source(int $secondsOld): Source
    {
        $source = $this->notebook->sources()->create(['title' => 'S', 'kind' => 'web', 'status' => 'pending', 'origin_url' => 'http://93.184.216.34/x']);
        DB::table('sources')->where('id', $source->id)->update(['updated_at' => now()->subSeconds($secondsOld)]);

        return $source;
    }

    /** A real queued job, in the database queue's own format. */
    private function queue(object $job, ?int $reservedAt = null): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default', 'attempts' => $reservedAt ? 1 : 0, 'reserved_at' => $reservedAt, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp,
            'payload' => json_encode(['displayName' => $job::class, 'data' => ['commandName' => $job::class, 'command' => serialize($job)]]),
        ]);
    }

    private function limit(string $job): int
    {
        return (new \ReflectionClass($job))->getProperty('timeout')->getDefaultValue() + OrphanSweep::MARGIN_SECONDS;
    }

    public function test_an_item_with_no_job_that_is_older_than_the_timeout_and_margin_is_failed_with_a_plain_sentence_and_a_log_line(): void
    {
        Log::spy();
        $orphan = $this->item('running', $this->limit(GenerateStudioItem::class) + 1);
        $pending = $this->item('pending', $this->limit(GenerateStudioItem::class) + 60);

        $done = app(OrphanSweep::class)->run();

        $this->assertEqualsCanonicalizing([$orphan->id, $pending->id], $done['items']);
        $this->assertSame('failed', $orphan->fresh()->status);
        $this->assertSame('Interrupted, please try again.', $orphan->fresh()->error);
        $this->assertNull($orphan->fresh()->step);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $message === 'Orphaned studio item marked failed' && $context['item_id'] === $orphan->id)->once();
    }

    public function test_a_live_job_is_left_alone_however_old_the_item_is_waiting_or_reserved(): void
    {
        $waiting = $this->item('pending', 10_000);
        $reserved = $this->item('running', 10_000);
        $this->queue(new GenerateStudioItem($waiting->id, 'media'));
        $this->queue(new GenerateStudioItem($reserved->id, 'media'), now()->timestamp);

        $done = app(OrphanSweep::class)->run();

        $this->assertSame([], $done['items']);
        $this->assertSame('pending', $waiting->fresh()->status);
        $this->assertSame('running', $reserved->fresh()->status);
    }

    public function test_the_boundary_exactly_at_the_limit_is_left_and_one_second_past_it_is_failed(): void
    {
        $limit = $this->limit(GenerateStudioItem::class);
        $at = $this->item('running', $limit);
        $past = $this->item('running', $limit + 1);

        $done = app(OrphanSweep::class)->run();

        $this->assertSame([$past->id], $done['items']);
        $this->assertSame('running', $at->fresh()->status);
    }

    public function test_a_recent_item_with_no_job_a_finished_item_and_another_items_job_are_left_alone(): void
    {
        $recent = $this->item('running', 60);
        $done = $this->item('done', 100_000);
        $failed = $this->item('failed', 100_000);
        $other = $this->item('running', 100_000);
        $this->queue(new GenerateStudioItem($other->id + 999, 'media'));

        $result = app(OrphanSweep::class)->run();

        $this->assertSame([$other->id], $result['items'], 'only the item whose own job is missing');
        $this->assertSame('running', $recent->fresh()->status);
        $this->assertSame('done', $done->fresh()->status);
        $this->assertSame('failed', $failed->fresh()->status);
    }

    public function test_a_source_is_swept_by_the_same_rule_with_its_own_longer_timeout(): void
    {
        $limit = $this->limit(EmbedSource::class);
        $this->assertGreaterThan($this->limit(GenerateStudioItem::class), $limit, 'a heavy source may legitimately run longer than a report');
        $orphan = $this->source($limit + 1);
        $young = $this->source($limit);
        $live = $this->source($limit + 1000);
        $this->queue(new EmbedSource($live->id));

        $done = app(OrphanSweep::class)->run();

        $this->assertSame([$orphan->id], $done['sources']);
        $this->assertSame('failed', $orphan->fresh()->status);
        $this->assertSame('Interrupted, please try again.', $orphan->fresh()->error);
        $this->assertSame('pending', $young->fresh()->status);
        $this->assertSame('pending', $live->fresh()->status);
    }

    public function test_the_command_runs_and_it_is_scheduled_every_five_minutes(): void
    {
        $orphan = $this->item('running', 100_000);

        $this->artisan('orphans:sweep')->expectsOutputToContain('1 studio item(s) and 0 source(s) marked failed.')->assertSuccessful();

        $this->assertSame('failed', $orphan->fresh()->status);
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'orphans:sweep'));
        $this->assertNotNull($event, 'orphans:sweep must be on the schedule');
        $this->assertSame('*/5 * * * *', $event->expression);
    }
}
