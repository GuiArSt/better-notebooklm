<?php

namespace Tests\Feature\Studio;

use App\Ai\Agents\RouterAgent;
use App\Models\Notebook;
use App\Models\Passage;
use App\Models\StudioItem;
use App\Models\UsageEvent;
use App\Models\User;
use App\Sources\EmbedSource;
use Axyr\Langfuse\LangfuseFacade as Langfuse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Image;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\ImageUsage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\ImageResponse;
use Tests\TestCase;

/** D-057: Claude looks at the picture it briefed, next to the brief: good or bad, two drawings at most. laravel/ai fakes only. */
class InfographicReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Notebook $notebook;

    /** @var list<string> the prompts the image model received, in order */
    private array $drawn = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests(); // fakes only: any real request to a provider fails the test
        Storage::fake('local');
        Langfuse::fake();
        Embeddings::fake();
        RouterAgent::fake(fn () => 'A summary.');
        config(['infographics.enabled' => true, 'ai.providers.openai.key' => 'sk-test-not-real']);
        $this->user = User::factory()->create(['tier' => 'pro', 'pro_until' => now()->addYear()]);
        $this->notebook = Notebook::create(['user_id' => $this->user->id, 'title' => 'T']);
        $source = $this->notebook->sources()->create(['title' => 'Orion notes', 'status' => 'pending', 'content' => 'The Orion Project launched in March 2031 with a budget of four million euros.']);
        app()->call([new EmbedSource($source->id), 'handle']);
    }

    private function png(int $grey): string
    {
        $im = imagecreatetruecolor(64, 64);
        imagefill($im, 0, 0, imagecolorallocate($im, $grey, $grey, $grey));
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    /** The image model hands back a different picture each time (grey 10, 20, ...), and remembers what it was asked. */
    private function fakeImages(): void
    {
        $n = 0;
        $this->drawn = [];
        Image::fake(function ($prompt) use (&$n) {
            $this->drawn[] = $prompt->prompt;
            $n++;

            return new ImageResponse(collect([new GeneratedImage(base64_encode($this->png($n * 10)), 'image/png')]), new ImageUsage, new Meta('openai', 'gpt-image-2.6-flare'));
        });
    }

    private function brief(): string
    {
        $ref = 'P'.Passage::firstOrFail()->id;

        return json_encode(['title' => 'Mission Orion at a glance', 'points' => array_map(fn ($i) => ['heading' => "Fact $i", 'figure' => (string) (2030 + $i), 'text' => "Mission fact number $i, stated plainly.", 'refs' => [$ref]], range(1, 5))]);
    }

    /** The text model: the brief first, then one answer per review, in order ("throw" makes that call fail). @param  list<string>  $reviews */
    private function fakeText(array $reviews): void
    {
        $calls = 0;
        RouterAgent::fake(function () use (&$calls, $reviews) {
            $calls++;
            if ($calls === 1) {
                return $this->brief();
            }
            $answer = $reviews[$calls - 2] ?? throw new \RuntimeException('an unexpected extra call');

            return $answer === 'throw' ? throw new \RuntimeException('the provider failed, with Mission fact 1 in it') : $answer;
        });
    }

    private function good(): string
    {
        return json_encode(['verdict' => 'good', 'problems' => []]);
    }

    private function bad(array $problems = ['The word "Orion" is misspelt in the title']): string
    {
        return json_encode(['verdict' => 'bad', 'problems' => $problems]);
    }

    private function make(): StudioItem
    {
        return StudioItem::findOrFail($this->actingAs($this->user)->postJson("/notebooks/{$this->notebook->id}/studio", ['kind' => 'infographic'])->assertStatus(202)->json('id'));
    }

    private function ledger(string $name): int
    {
        return UsageEvent::where('prompt_name', $name)->count();
    }

    public function test_good_on_the_first_look_is_one_picture_and_one_review(): void
    {
        $this->fakeImages();
        $this->fakeText([$this->good()]);

        $item = $this->make();

        $this->assertSame('done', $item->status, (string) $item->error);
        $this->assertCount(1, $this->drawn);
        $this->assertSame(1, $this->ledger('studio.infographic.review'));
        $row = UsageEvent::where('prompt_name', 'studio.infographic.review')->sole();
        $this->assertSame('chat', $row->kind);
        $this->assertGreaterThan(0, $row->cost_micro_usd, 'its own ledger row, with the provider usage priced');
        $this->assertSame(1, UsageEvent::where('kind', 'image')->count());
        $this->assertSame(['verdict' => 'good', 'problems' => [], 'attempts' => 1], $item->settings['review']);
        $this->assertSame($this->png(10), Storage::disk('local')->get($item->file_path));
    }

    public function test_the_review_gets_the_picture_and_the_brief_and_nothing_from_the_sources(): void
    {
        $this->fakeImages();
        $this->fakeText([$this->good()]);

        $this->make();

        RouterAgent::assertPrompted(fn ($p) => str_contains($p->agent->instructions(), 'review an infographic')
            && $p->attachments->count() === 1
            && $p->attachments->first()->content() === $this->png(10)
            && str_contains($p->prompt, '<source_data from="brief">') && str_contains($p->prompt, 'Mission fact number 1')
            && ! str_contains($p->prompt, 'Orion Project') && ! str_contains($p->prompt, 'four million'));
    }

    public function test_bad_then_good_draws_twice_reviews_twice_and_puts_the_problems_into_the_second_prompt(): void
    {
        $this->fakeImages();
        $this->fakeText([$this->bad(['The year 2031 is drawn as 2013']), $this->good()]);

        $item = $this->make();

        $this->assertSame('done', $item->status, (string) $item->error);
        $this->assertCount(2, $this->drawn);
        $this->assertStringNotContainsString('Fix these problems', $this->drawn[0]);
        $this->assertStringContainsString('Fix these problems seen in the last attempt: The year 2031 is drawn as 2013', $this->drawn[1]);
        $this->assertSame(2, $this->ledger('studio.infographic.review'));
        $this->assertSame(2, UsageEvent::where('kind', 'image')->count());
        $this->assertSame(['verdict' => 'good', 'problems' => [], 'attempts' => 2], $item->settings['review']);
        $this->assertSame($this->png(20), Storage::disk('local')->get($item->file_path), 'the second picture is the one kept');
    }

    public function test_bad_twice_keeps_the_second_picture_and_stores_the_problems_for_a_visible_note(): void
    {
        $this->fakeImages();
        $this->fakeText([$this->bad(['Point 2 is missing']), $this->bad(['The figure 2033 is missing', 'The title is cut off'])]);

        $item = $this->make();

        $this->assertSame('done', $item->status, (string) $item->error);
        $this->assertCount(2, $this->drawn, 'two drawings at most');
        $this->assertSame(['verdict' => 'bad', 'problems' => ['The figure 2033 is missing', 'The title is cut off'], 'attempts' => 2], $item->settings['review']);
        $this->assertSame($this->png(20), Storage::disk('local')->get($item->file_path));
        $this->actingAs($this->user)->getJson("/notebooks/{$this->notebook->id}/studio/{$item->id}")->assertOk()
            ->assertJsonPath('settings.review.verdict', 'bad')->assertJsonPath('settings.review.problems.1', 'The title is cut off')->assertJsonPath('status', 'done');
    }

    public function test_at_most_five_problems_are_kept_and_tags_are_stripped(): void
    {
        $this->fakeImages();
        $this->fakeText([$this->bad(['<b>one</b>', 'two', 'three', 'four', 'five', 'six']), $this->good()]);

        $this->make();

        $this->assertStringContainsString('one; two; three; four; five', $this->drawn[1]);
        $this->assertStringNotContainsString('six', $this->drawn[1]);
        $this->assertStringNotContainsString('<b>', $this->drawn[1]);
    }

    public function test_a_review_that_fails_keeps_the_picture_records_unavailable_and_logs_the_class(): void
    {
        Log::spy();
        $this->fakeImages();
        $this->fakeText(['throw']);

        $item = $this->make();

        $this->assertSame('done', $item->status, (string) $item->error);
        $this->assertCount(1, $this->drawn, 'no second drawing without a verdict');
        $this->assertSame(['verdict' => 'unavailable', 'problems' => [], 'attempts' => 1], $item->settings['review']);
        Storage::disk('local')->assertExists($item->file_path);
        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context = []) => str_contains($message, 'Infographic review') && ($context['error'] ?? null) === 'RuntimeException')->once();
        $this->assertSame(0, UsageEvent::where('prompt_name', 'studio.infographic.review')->count(), 'a failed text call is released, not charged (the Router\'s rule for every text call)');
        $this->assertStringContainsString('RuntimeException', json_encode(Langfuse::getFacadeRoot()->exported()) ?: '', 'the trace names the class');
        $this->assertStringNotContainsString('the provider failed', json_encode(Langfuse::getFacadeRoot()->exported()) ?: '', 'never the provider\'s text');
    }

    public function test_a_review_that_is_not_json_even_after_the_repair_is_unavailable_not_a_failure(): void
    {
        $this->fakeImages();
        $this->fakeText(['I think it looks fine', 'still not json']);

        $item = $this->make();

        $this->assertSame('done', $item->status, (string) $item->error);
        $this->assertSame('unavailable', $item->settings['review']['verdict']);
        Storage::disk('local')->assertExists($item->file_path);
    }

    public function test_an_answer_with_no_valid_verdict_is_unavailable(): void
    {
        $this->fakeImages();
        $this->fakeText([json_encode(['verdict' => 'maybe', 'problems' => []])]);

        $item = $this->make();

        $this->assertSame('unavailable', $item->settings['review']['verdict']);
        $this->assertCount(1, $this->drawn);
    }

    public function test_the_estimate_covers_two_pictures_and_two_looks(): void
    {
        $r = $this->actingAs($this->user)->getJson("/notebooks/{$this->notebook->id}/studio/estimate?kind=infographic&options[orientation]=landscape")->assertOk()->json();

        $this->assertGreaterThan(2 * 9_000, $r['estimate_micro_usd'], 'at least two landscape pictures at 9,000 plus the brief and the looks');
    }

    public function test_the_picture_is_never_in_the_trace(): void
    {
        $this->fakeImages();
        $this->fakeText([$this->good()]);

        $this->make();

        $trace = json_encode(Langfuse::getFacadeRoot()->exported()) ?: '';
        $this->assertStringNotContainsString(base64_encode($this->png(10)), $trace);
        $this->assertStringContainsString('studio.infographic.review', $trace);
    }
}
