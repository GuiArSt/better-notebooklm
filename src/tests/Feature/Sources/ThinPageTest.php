<?php

namespace Tests\Feature\Sources;

use App\Ai\Agents\RouterAgent;
use App\Models\Notebook;
use App\Models\Source;
use App\Models\User;
use App\Sources\EmbedSource;
use App\Sources\Parsers;
use Axyr\Langfuse\LangfuseFacade as Langfuse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;
use Tests\TestCase;

/** D-060, D-038: a fetched page with almost no readable text (a JavaScript-only page) must FAIL with a clear message, never become a near-empty ready source. */
class ThinPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Notebook $notebook;

    protected function setUp(): void
    {
        parent::setUp();
        Langfuse::fake();
        Embeddings::fake();
        RouterAgent::fake(fn () => 'A short summary.');
        $this->user = User::factory()->create();
        $this->notebook = Notebook::create(['user_id' => $this->user->id, 'title' => 'T']);
    }

    private int $n = 0;

    /** One page per call, at its own address (Http::fake keeps the first stub that matches, so a new body needs a new address). */
    private function page(string $body, string $type = 'text/html'): Source
    {
        $path = 'page'.(++$this->n);
        Http::fake(["93.184.216.34/$path" => Http::response($body, 200, ['Content-Type' => $type])]);
        $source = $this->notebook->sources()->create(['title' => 'S', 'kind' => 'web', 'status' => 'pending', 'origin_url' => "http://93.184.216.34/$path"]);

        app()->call([new EmbedSource($source->id), 'handle']);

        return $source->fresh();
    }

    private function jsOnly(): string
    {
        return '<html><head><title>My App</title></head><body><div id="root"></div><noscript>Please enable JavaScript to run this app.</noscript>'
            .'<script src="/static/bundle.js"></script><script>window.__INITIAL_STATE__ = {"a":1};</script></body></html>';
    }

    public function test_a_javascript_only_page_fails_with_the_clear_message_and_is_not_stored_as_a_ready_source(): void
    {
        $source = $this->page($this->jsOnly());

        $this->assertSame('failed', $source->status);
        $this->assertSame('This page had almost no readable text; it may need JavaScript. Upload a PDF or paste the text instead.', $source->error);
        $this->assertNull($source->content, 'nothing near-empty is kept');
        $this->assertSame(0, $source->passages()->count());
        $this->assertSame(0, DB::table('usage_events')->count(), 'and nothing was spent embedding it');
    }

    public function test_a_page_just_under_the_limit_fails_and_one_at_the_limit_is_read(): void
    {
        config(['sources.web.min_chars' => 200]);
        $body = fn (int $n) => '<html><body><article><p>'.str_repeat('a', $n).'</p></article></body></html>';

        $under = $this->page($body(150));
        $this->assertSame('failed', $under->status);

        $enough = $this->page('<html><body><article><p>'.str_repeat('Harbours and fish at Skagen. ', 12).'</p></article></body></html>');
        $this->assertSame('ready', $enough->status, (string) $enough->error);
        $this->assertGreaterThanOrEqual(200, $enough->chars);
    }

    public function test_the_limit_is_a_config_value(): void
    {
        $short = '<html><body><article><p>'.str_repeat('Harbours and fish. ', 5).'</p></article></body></html>';   // about 95 characters

        config(['sources.web.min_chars' => 50]);
        $this->assertSame('ready', $this->page($short)->status);

        config(['sources.web.min_chars' => 500]);
        $this->assertSame('failed', $this->page($short)->status);
    }

    public function test_a_page_with_no_text_at_all_says_the_same_plain_thing(): void
    {
        $source = $this->page('<html><body></body></html>');

        $this->assertSame('failed', $source->status);
        $this->assertSame(Parsers::THIN_PAGE, $source->error);
    }

    public function test_a_plain_text_page_that_is_nearly_empty_fails_too_and_a_long_one_does_not(): void
    {
        $this->assertSame('failed', $this->page('ok', 'text/plain')->status);
        $long = $this->page(str_repeat('A line of plain text about harbours. ', 10), 'text/plain');
        $this->assertSame('ready', $long->status, (string) $long->error);
    }

    public function test_pasted_text_and_a_pdf_are_not_held_to_the_web_limit(): void
    {
        $source = $this->notebook->sources()->create(['title' => 'Short note', 'kind' => 'text', 'status' => 'pending', 'content' => 'A short pasted note.']);

        app()->call([new EmbedSource($source->id), 'handle']);

        $this->assertSame('ready', $source->fresh()->status, 'a person who pastes a short text meant it');
    }

    public function test_retry_still_works_after_a_thin_page_and_reads_it_when_it_now_has_text(): void
    {
        // The first fetch is the JavaScript-only page; the second (after the retry) has real text.
        Http::fakeSequence('93.184.216.34/*')
            ->push($this->jsOnly(), 200, ['Content-Type' => 'text/html'])
            ->push('<html><body><article><p>'.str_repeat('Harbours and fish at Skagen. ', 12).'</p></article></body></html>', 200, ['Content-Type' => 'text/html']);
        $source = $this->notebook->sources()->create(['title' => 'S', 'kind' => 'web', 'status' => 'pending', 'origin_url' => 'http://93.184.216.34/app']);

        app()->call([new EmbedSource($source->id), 'handle']);
        $this->assertSame('failed', $source->fresh()->status);

        $this->actingAs($this->user)->post("/notebooks/{$this->notebook->id}/sources/{$source->id}/retry")->assertRedirect();

        // The test queue runs jobs at once, so the retry has already read the page again, and this time it had text.
        $this->assertSame('ready', $source->fresh()->status, (string) $source->fresh()->error);
        $this->assertNull($source->fresh()->error);
        $this->assertGreaterThan(0, $source->fresh()->passages()->count());
    }
}
