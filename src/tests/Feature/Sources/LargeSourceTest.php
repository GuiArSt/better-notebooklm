<?php

namespace Tests\Feature\Sources;

use App\Ai\Agents\RouterAgent;
use App\Ai\Pricing;
use App\Models\Notebook;
use App\Models\Source;
use App\Models\User;
use App\Sources\EmbedSource;
use App\Sources\LargeSource;
use Axyr\Langfuse\LangfuseFacade as Langfuse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;
use Tests\TestCase;

/** D-040: heavy sources are accepted up to a cap, flagged "large" above a lower line with their cost, and refused over the cap with the cap stated. */
class LargeSourceTest extends TestCase
{
    use RefreshDatabase;

    private function notebook(string $tier = 'free'): array
    {
        $user = User::factory()->create(['tier' => $tier, 'pro_until' => $tier === 'pro' ? now()->addDays(30) : null]);

        return [$user, Notebook::create(['user_id' => $user->id, 'title' => 'Heavy'])];
    }

    public function test_the_default_cap_and_the_large_line_are_the_proved_numbers(): void
    {
        $this->assertSame(1_200_000, (int) config('retrieval.max_source_chars'));
        $this->assertSame(200_000, (int) config('retrieval.large_source_chars'));
    }

    public function test_pasted_text_at_the_cap_is_accepted_and_one_over_is_refused_with_the_cap_stated(): void
    {
        Queue::fake();
        config(['retrieval.max_source_chars' => 1_000]);
        [$user, $notebook] = $this->notebook();

        $this->actingAs($user)->post(route('sources.store', $notebook), ['title' => 'Exactly', 'content' => str_repeat('a', 1_000)])->assertSessionDoesntHaveErrors();
        $this->assertSame(1_000, Source::where('title', 'Exactly')->value('chars'));

        $this->actingAs($user)->post(route('sources.store', $notebook), ['title' => 'Over', 'content' => str_repeat('a', 1_001)])
            ->assertSessionHasErrors(['content' => 'This text is longer than the limit of 1,000 characters.']);
        $this->assertSame(0, Source::where('title', 'Over')->count());
    }

    public function test_a_text_file_over_the_cap_is_refused_with_the_cap_stated(): void
    {
        Queue::fake();
        config(['retrieval.max_source_chars' => 1_000]);
        [$user, $notebook] = $this->notebook();

        $this->actingAs($user)->post(route('sources.store', $notebook), ['file' => UploadedFile::fake()->createWithContent('big.txt', str_repeat('a', 1_001))])
            ->assertSessionHasErrors(['file' => 'This text file is longer than the limit of 1,000 characters.']);
    }

    public function test_a_web_page_whose_text_is_over_the_cap_fails_the_source_and_says_the_cap(): void
    {
        Langfuse::fake();
        Embeddings::fake();
        RouterAgent::fake(fn () => 'ok');
        config(['retrieval.max_source_chars' => 500]);
        $html = '<html><body><article><p>'.str_repeat('Long sentence about harbours and fish. ', 40).'</p></article></body></html>';
        Http::fake(['93.184.216.34/*' => Http::response($html, 200, ['Content-Type' => 'text/html'])]);
        [, $notebook] = $this->notebook();
        $source = $notebook->sources()->create(['title' => 'S', 'kind' => 'web', 'status' => 'pending', 'origin_url' => 'http://93.184.216.34/long']);

        app()->call([new EmbedSource($source->id), 'handle']);

        $source->refresh();
        $this->assertSame('failed', $source->status);
        $this->assertSame('This source is longer than the limit of 500 characters.', $source->error);
        $this->assertSame(0, $source->passages()->count());
    }

    public function test_a_web_page_under_the_cap_records_its_length(): void
    {
        Langfuse::fake();
        Embeddings::fake();
        RouterAgent::fake(fn () => 'ok');
        $html = '<html><body><article><p>'.str_repeat('Long sentence about harbours and fish. ', 40).'</p></article></body></html>';
        Http::fake(['93.184.216.34/*' => Http::response($html, 200, ['Content-Type' => 'text/html'])]);
        [, $notebook] = $this->notebook();
        $source = $notebook->sources()->create(['title' => 'S', 'kind' => 'web', 'status' => 'pending', 'origin_url' => 'http://93.184.216.34/long']);

        app()->call([new EmbedSource($source->id), 'handle']);

        $source->refresh();
        $this->assertSame('ready', $source->status);
        $this->assertSame(mb_strlen((string) $source->content), $source->chars);
    }

    public function test_the_large_line_is_exclusive_and_only_a_large_source_carries_page_count_and_cost(): void
    {
        config(['retrieval.large_source_chars' => 1_000]);
        [$user, $notebook] = $this->notebook();
        $this->actingAs($user);

        $edge = $notebook->sources()->create(['title' => 'Edge', 'kind' => 'text', 'status' => 'ready', 'chars' => 1_000]);
        $over = $notebook->sources()->create(['title' => 'Over', 'kind' => 'text', 'status' => 'ready', 'chars' => 1_001]);

        $a = Source::find($edge->id)->toArray();
        $this->assertFalse($a['large']);
        $this->assertNull($a['estimated_cost_micro_usd']);
        $this->assertNull($a['page_count']);

        $b = Source::find($over->id)->toArray();
        $this->assertTrue($b['large']);
        $this->assertSame(1, $b['page_count']);
        $this->assertTrue($b['page_count_estimated']);
        $this->assertGreaterThan(0, $b['estimated_cost_micro_usd']);
    }

    public function test_a_pdf_reports_its_real_page_count_not_an_estimate(): void
    {
        config(['retrieval.large_source_chars' => 100]);
        [$user, $notebook] = $this->notebook();
        $this->actingAs($user);
        $pdf = $notebook->sources()->create([
            'title' => 'Book', 'kind' => 'pdf', 'status' => 'ready', 'chars' => 590_000,
            'pages' => [['page' => 1, 'start' => 0, 'end' => 10], ['page' => 2, 'start' => 12, 'end' => 30], ['page' => 3, 'start' => 32, 'end' => 50]],
        ]);

        $json = Source::find($pdf->id)->toArray();

        $this->assertSame(3, $json['page_count']);
        $this->assertFalse($json['page_count_estimated']);
    }

    public function test_a_source_with_no_length_yet_is_not_large_and_has_no_estimate(): void
    {
        [$user, $notebook] = $this->notebook();
        $this->actingAs($user);
        $pending = $notebook->sources()->create(['title' => 'Pending', 'kind' => 'pdf', 'status' => 'pending']);

        $json = Source::find($pending->id)->toArray();

        $this->assertFalse($json['large']);
        $this->assertNull($json['estimated_cost_micro_usd']);
    }

    public function test_the_estimate_comes_from_the_price_config_and_a_pro_user_pays_more_than_a_free_one(): void
    {
        $large = app(LargeSource::class);
        $pricing = app(Pricing::class);
        $chars = 590_540;

        // Embedding all of it, plus the summary of its first 24,000 characters and the longest summary the model may write.
        $free = $pricing->cost((string) config('models.embedding.model'), $pricing->tokensForChars($chars))
            + $pricing->cost((string) config('models.chat.free.model'), $pricing->tokensForChars(24_000 + 1_000), (int) config('models.max_output_tokens'));

        $this->assertSame($free, $large->estimate($chars, 'free'));
        $this->assertGreaterThan($large->estimate($chars, 'free'), $large->estimate($chars, 'pro'));
        // The book measured on dev cost $0.024 in all; the estimate is the safe side of that, and in the same cents.
        $this->assertGreaterThan(23_939, $large->estimate($chars, 'free'));
        $this->assertLessThan(100_000, $large->estimate($chars, 'free'));
    }

    public function test_a_heavy_source_job_may_run_for_minutes(): void
    {
        $this->assertGreaterThanOrEqual(600, (new EmbedSource(1))->timeout);
    }
}
