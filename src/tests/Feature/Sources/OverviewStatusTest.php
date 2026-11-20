<?php

namespace Tests\Feature\Sources;

use App\Ai\Agents\RouterAgent;
use App\Models\Notebook;
use App\Models\NotebookOverview;
use App\Models\User;
use App\Sources\BuildOverview;
use App\Studio\OrphanSweep;
use Axyr\Langfuse\LangfuseFacade as Langfuse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Embeddings;
use Tests\TestCase;

/** Gap 4 (no silent states): the overview says whether it is building, ready or failed, so the page can tell "failed" from "nothing to say". */
class OverviewStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Notebook $notebook;

    protected function setUp(): void
    {
        parent::setUp();
        Langfuse::fake();
        Embeddings::fake();
        $this->user = User::factory()->create();
        $this->notebook = Notebook::create(['user_id' => $this->user->id, 'title' => 'T']);
    }

    private function readySource(): void
    {
        $this->notebook->sources()->create(['title' => 'S', 'kind' => 'text', 'status' => 'ready', 'checked' => true, 'content' => 'The Orion Project launched in March 2031 with a budget of four million euros.', 'language' => 'en']);
    }

    private function build(): void
    {
        app()->call([new BuildOverview($this->notebook->id), 'handle']);
    }

    private function overview(): array
    {
        return $this->actingAs($this->user)->getJson("/notebooks/{$this->notebook->id}/overview")->assertOk()->json();
    }

    public function test_a_good_answer_ends_ready_and_the_get_says_so_with_the_text(): void
    {
        $this->readySource();
        RouterAgent::fake(fn () => '{"summary":"A short summary.","questions":["One?","Two?","Three?"]}');

        $this->build();

        $json = $this->overview();
        $this->assertSame('ready', $json['status']);
        $this->assertSame('A short summary.', $json['summary']);
        $this->assertCount(3, $json['questions']);
    }

    public function test_an_unreadable_answer_ends_failed_and_the_last_good_text_stays(): void
    {
        $this->readySource();
        NotebookOverview::create(['notebook_id' => $this->notebook->id, 'summary' => 'The old summary.', 'questions' => ['Old?'], 'status' => 'ready']);
        RouterAgent::fake(fn () => 'not json at all');

        $this->build();

        $json = $this->overview();
        $this->assertSame('failed', $json['status']);
        $this->assertSame('The old summary.', $json['summary'], 'the page can still show the last good overview');
    }

    public function test_a_provider_error_ends_failed_with_nothing_to_show_and_not_as_an_empty_notebook(): void
    {
        $this->readySource();
        RouterAgent::fake(fn () => throw new \RuntimeException('the provider is down'));

        $this->build();

        $json = $this->overview();
        $this->assertSame('failed', $json['status']);
        $this->assertNull($json['summary']);
        $this->assertSame([], $json['questions']);
    }

    public function test_while_building_the_get_says_building_with_no_text_yet(): void
    {
        NotebookOverview::create(['notebook_id' => $this->notebook->id, 'status' => 'building']);

        $json = $this->overview();

        $this->assertSame('building', $json['status']);
        $this->assertNull($json['summary']);
        $this->assertSame([], $json['questions']);
    }

    public function test_a_notebook_never_asked_has_status_null_and_one_with_no_checked_source_leaves_no_row(): void
    {
        $this->assertNull($this->overview()['status']);

        RouterAgent::fake(fn () => '{"summary":"x","questions":["a","b","c"]}');
        $this->build(); // no source at all

        $this->assertSame(0, NotebookOverview::count());
        $this->assertNull($this->overview()['status']);
    }

    public function test_a_row_from_before_the_states_existed_reads_as_ready(): void
    {
        NotebookOverview::create(['notebook_id' => $this->notebook->id, 'summary' => 'From before.', 'questions' => ['Q?']]);

        $this->assertSame('ready', $this->overview()['status']);
    }

    public function test_a_killed_job_reported_through_failed_ends_the_building_overview_but_not_a_ready_one(): void
    {
        NotebookOverview::create(['notebook_id' => $this->notebook->id, 'status' => 'building']);
        (new BuildOverview($this->notebook->id))->failed(new \RuntimeException);
        $this->assertSame('failed', $this->overview()['status']);

        NotebookOverview::where('notebook_id', $this->notebook->id)->update(['status' => 'ready', 'summary' => 's', 'questions' => json_encode(['q'])]);
        (new BuildOverview($this->notebook->id))->failed(new \RuntimeException);
        $this->assertSame('ready', $this->overview()['status']);
    }

    public function test_another_users_overview_is_not_readable(): void
    {
        $this->actingAs(User::factory()->create())->getJson("/notebooks/{$this->notebook->id}/overview")->assertNotFound();
    }

    public function test_the_migration_is_rollback_safe(): void
    {
        $migration = require base_path('database/migrations/2026_11_25_000001_notebook_overviews_status.php');
        $other = Notebook::create(['user_id' => $this->user->id, 'title' => 'Other']);
        NotebookOverview::create(['notebook_id' => $this->notebook->id, 'status' => 'building']);                     // no text: only exists as a state
        NotebookOverview::create(['notebook_id' => $other->id, 'summary' => 'Kept.', 'questions' => ['Q?'], 'status' => 'ready']);

        $migration->down();

        $this->assertFalse(Schema::hasColumn('notebook_overviews', 'status'));
        $this->assertSame(['Kept.'], DB::table('notebook_overviews')->pluck('summary')->all(), 'the state-only row is dropped, the real overview kept');

        $migration->up();

        $this->assertTrue(Schema::hasColumn('notebook_overviews', 'status'));
    }

    public function test_a_building_or_failed_row_never_stores_null_so_the_code_before_the_states_can_still_read_it(): void
    {
        $this->readySource();
        $seen = [];
        RouterAgent::fake(function () use (&$seen) {
            // While the model call runs, the row says building: read it as the old code would, from the raw table.
            $seen = (array) DB::table('notebook_overviews')->where('notebook_id', $this->notebook->id)->first();

            return 'not json at all';
        });

        $this->build();

        $this->assertSame('building', $seen['status']);
        $this->assertSame('[]', $seen['questions'], 'an array, not NULL');
        $this->assertSame('', $seen['summary']);
        $after = (array) DB::table('notebook_overviews')->where('notebook_id', $this->notebook->id)->first();
        $this->assertSame('failed', $after['status']);
        $this->assertSame('[]', $after['questions']);
        $this->assertSame('', $after['summary']);
        $this->assertSame(['summary' => null, 'questions' => [], 'status' => 'failed'], $this->overview(), 'the page still sees "no summary"');
    }

    public function test_a_building_row_made_with_no_text_by_the_older_code_is_made_an_array_when_it_is_next_marked(): void
    {
        NotebookOverview::create(['notebook_id' => $this->notebook->id, 'status' => 'building']); // summary and questions NULL, as the first version wrote it

        NotebookOverview::mark($this->notebook->id, 'failed');

        $row = (array) DB::table('notebook_overviews')->where('notebook_id', $this->notebook->id)->first();
        $this->assertSame('[]', $row['questions']);
        $this->assertSame('', $row['summary']);
    }

    public function test_marking_never_touches_the_last_good_text(): void
    {
        NotebookOverview::create(['notebook_id' => $this->notebook->id, 'summary' => 'Good.', 'questions' => ['Q1?', 'Q2?', 'Q3?'], 'status' => 'ready']);

        NotebookOverview::begin($this->notebook->id);
        $this->assertSame('Good.', NotebookOverview::first()->summary);
        NotebookOverview::mark($this->notebook->id, 'failed');

        $row = NotebookOverview::first();
        $this->assertSame('failed', $row->status);
        $this->assertSame('Good.', $row->summary);
        $this->assertSame(['Q1?', 'Q2?', 'Q3?'], $row->questions);
    }

    public function test_the_orphan_sweep_also_leaves_an_array_not_null(): void
    {
        NotebookOverview::create(['notebook_id' => $this->notebook->id, 'status' => 'building']);
        DB::table('notebook_overviews')->update(['updated_at' => now()->subDay()]);

        app(OrphanSweep::class)->run();

        $row = (array) DB::table('notebook_overviews')->where('notebook_id', $this->notebook->id)->first();
        $this->assertSame('failed', $row['status']);
        $this->assertSame('[]', $row['questions']);
    }
}
