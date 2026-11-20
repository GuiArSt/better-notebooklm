<?php

namespace Tests\Feature\Icons;

use App\Ai\Exceptions\CapExceeded;
use App\Ai\Exceptions\UnpricedModel;
use App\Ai\Router;
use App\Icons\GenerateNotebookIcon;
use App\Icons\IconImage;
use App\Icons\NotebookIcons;
use App\Models\Notebook;
use App\Models\UsageEvent;
use App\Models\User;
use Axyr\Langfuse\LangfuseFacade as Langfuse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Image;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\ImageUsage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\ImageResponse;
use Tests\TestCase;

/** D-032: generated notebook icons. laravel/ai fakes only: no real image is ever requested. */
class NotebookIconTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Langfuse::fake();
        Storage::fake('local');
        config(['images.enabled' => true, 'ai.providers.openai.key' => 'sk-test-not-real']);
    }

    private function png(): string
    {
        $im = imagecreatetruecolor(64, 64);
        imagefill($im, 0, 0, imagecolorallocate($im, 20, 120, 200));
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    private function fakeImage(): void
    {
        Image::fake([fn () => new ImageResponse(collect([new GeneratedImage(base64_encode($this->png()), 'image/png')]), new ImageUsage, new Meta('openai', 'gpt-image-2.5-flare'))]);
    }

    private function notebook(?User $user = null, string $title = 'Sourdough baking'): Notebook
    {
        return Notebook::create(['user_id' => ($user ?? User::factory()->create())->id, 'title' => $title]);
    }

    public function test_the_switch_off_means_no_job_and_no_status(): void
    {
        Queue::fake();
        config(['images.enabled' => false]);

        $n = $this->notebook();

        Queue::assertNothingPushed();
        $this->assertNull($n->fresh()->icon_status);
    }

    public function test_without_a_key_nothing_is_queued_either(): void
    {
        Queue::fake();
        config(['ai.providers.openai.key' => '']);

        $this->notebook();

        Queue::assertNothingPushed();
    }

    public function test_a_new_notebook_queues_one_job_and_asking_again_queues_no_second(): void
    {
        Queue::fake();

        $n = $this->notebook();

        Queue::assertPushed(GenerateNotebookIcon::class, 1);
        $this->assertSame('queued', $n->fresh()->icon_status);
        $this->assertFalse(app(NotebookIcons::class)->request($n->fresh()), 'the atomic flip is taken only once');
        Queue::assertPushed(GenerateNotebookIcon::class, 1);
    }

    public function test_the_job_runs_once_draws_stores_a_256px_webp_and_writes_one_priced_ledger_row(): void
    {
        $this->fakeImage();
        $n = $this->notebook();
        // The sync queue in tests runs the job as soon as the notebook is created.
        $n = $n->fresh();

        $this->assertSame('done', $n->icon_status);
        $this->assertSame("icons/{$n->user_id}/{$n->id}.webp", $n->icon_path);
        Storage::disk('local')->assertExists($n->icon_path);
        $stored = Storage::disk('local')->get($n->icon_path);
        $this->assertSame(hash('sha256', $stored), $n->icon_sha256);
        $this->assertSame([256, 256], [$n->icon_width, $n->icon_height]);
        $this->assertSame('image/webp', getimagesizefromstring($stored)['mime']);

        $row = UsageEvent::where('kind', 'image')->sole();
        $this->assertSame(6_000, $row->cost_micro_usd);
        $this->assertSame($n->user_id, $row->user_id);
        $this->assertSame('openai', $row->provider);

        // A duplicate job finds nothing to do and pays nothing.
        (new GenerateNotebookIcon($n->id))->handle(app(Router::class), app(IconImage::class));
        $this->assertSame(1, UsageEvent::where('kind', 'image')->count());
    }

    public function test_the_prompt_carries_the_style_and_the_title_but_the_trace_does_not_carry_the_title(): void
    {
        $this->fakeImage();
        $this->notebook(null, 'Secret Project Orchid');

        Image::assertGenerated(fn ($prompt) => str_contains($prompt->prompt, 'no text, no letters, no logos') && str_contains($prompt->prompt, 'Secret Project Orchid') && $prompt->size === '1024x1024' && $prompt->quality === 'low');
        $trace = json_encode(Langfuse::getFacadeRoot()->exported()) ?: '';
        $this->assertStringContainsString('notebook.icon', $trace, 'the call was traced');
        $this->assertStringNotContainsString('Secret Project Orchid', $trace);
    }

    public function test_a_failure_writes_a_failed_zero_cost_row_naming_the_class_only_and_keeps_the_emoji(): void
    {
        Image::fake([fn () => throw new \RuntimeException('boom with the title Secret Project Orchid')]);

        $n = $this->notebook(null, 'Secret Project Orchid')->fresh();

        $this->assertSame('failed', $n->icon_status);
        $this->assertSame('RuntimeException', $n->icon_error);
        $row = UsageEvent::where('kind', 'image')->sole();
        $this->assertSame(0, $row->cost_micro_usd);
        $this->assertSame('RuntimeException', $row->units['error']);
        $this->assertStringNotContainsString('Orchid', json_encode($row->toArray()));
        $this->assertNull(NotebookIcons::url($n));
        $this->assertSame(0, \DB::table('ledger_reservations')->count(), 'the hold is gone');
    }

    public function test_an_empty_answer_is_an_error(): void
    {
        Image::fake([fn () => new ImageResponse(collect([new GeneratedImage('', 'image/png')]), new ImageUsage, new Meta('openai', 'm'))]);

        $n = $this->notebook()->fresh();

        $this->assertSame('failed', $n->icon_status);
        $this->assertSame('EmptyImage', $n->icon_error);
    }

    public function test_bytes_that_are_not_an_image_fail_the_icon_and_nothing_is_stored(): void
    {
        Image::fake([fn () => new ImageResponse(collect([new GeneratedImage(base64_encode('not-an-image'), 'image/png')]), new ImageUsage, new Meta('openai', 'm'))]);

        $n = $this->notebook()->fresh();

        $this->assertSame('failed', $n->icon_status);
        $this->assertSame('NotAnImage', $n->icon_error);
        $this->assertNull($n->icon_path);
        $this->assertSame([], Storage::disk('local')->allFiles('icons'));
        $this->assertNull(NotebookIcons::url($n));
    }

    public function test_an_unpriced_model_is_refused_before_the_provider_is_called(): void
    {
        Image::fake();
        config(['images.model' => 'some-new-image-model']);

        $n = $this->notebook()->fresh();

        Image::assertNothingGenerated();
        $this->assertSame('failed', $n->icon_status);
        $this->assertSame(UnpricedModel::class, 'App\\Ai\\Exceptions\\'.$n->icon_error);
        $this->assertSame(0, UsageEvent::where('kind', 'image')->count());
    }

    public function test_the_owners_cap_and_the_daily_icon_budget_stop_the_call_and_the_emoji_stays(): void
    {
        Image::fake();
        $user = User::factory()->create();
        config(['ledger.user_caps.free' => 500_000]); // this test is about a user near a small cap, whatever the default
        UsageEvent::create(['user_id' => $user->id, 'provider' => 'x', 'model' => 'x', 'kind' => 'chat', 'units' => [], 'cost_micro_usd' => 499_999]);

        $n = $this->notebook($user)->fresh(); // $0.50 cap, $0.499999 spent: a 6,000 µ$ hold does not fit
        $this->assertSame('failed', $n->icon_status);
        $this->assertSame('CapExceeded', $n->icon_error);

        config(['images.daily_cap_micro_usd' => 5_000]); // below one icon's price
        $other = $this->notebook()->fresh();
        $this->assertSame('failed', $other->icon_status);
        $this->expectException(CapExceeded::class);
        app(Router::class)->image($other->user, 'x');
    }

    public function test_only_the_owner_can_read_the_icon(): void
    {
        $this->fakeImage();
        $n = $this->notebook()->fresh();

        $this->actingAs($n->user)->get("/notebooks/{$n->id}/icon")->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs(User::factory()->create())->get("/notebooks/{$n->id}/icon")->assertNotFound();
        auth()->logout();
        $this->get("/notebooks/{$n->id}/icon")->assertRedirect('/login');
        $this->assertStringStartsWith("/notebooks/{$n->id}/icon?v=", (string) NotebookIcons::url($n));
    }

    public function test_a_notebook_without_an_icon_answers_404_and_deleting_the_notebook_deletes_the_file(): void
    {
        $this->fakeImage();
        $n = $this->notebook()->fresh();
        $path = $n->icon_path;
        Storage::disk('local')->assertExists($path);

        $n->delete();

        Storage::disk('local')->assertMissing($path);
    }

    public function test_the_backfill_command_queues_the_notebooks_that_have_none(): void
    {
        Queue::fake();
        config(['images.enabled' => false]);
        $a = $this->notebook();
        $b = $this->notebook();
        config(['images.enabled' => true]);

        $this->artisan('notebooks:icons', ['--missing' => true])->expectsOutputToContain('2 icon(s) queued')->assertSuccessful();
        Queue::assertPushed(GenerateNotebookIcon::class, 2);
        $this->artisan('notebooks:icons', ['--missing' => true])->expectsOutputToContain('0 icon(s) queued')->assertSuccessful();

        config(['images.enabled' => false]);
        $this->artisan('notebooks:icons', ['--missing' => true])->assertFailed();
        $this->assertSame('queued', $a->fresh()->icon_status);
        $this->assertSame('queued', $b->fresh()->icon_status);
    }
}
