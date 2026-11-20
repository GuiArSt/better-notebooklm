<?php

namespace Tests\Feature\Sources;

use App\Ai\Agents\RouterAgent;
use App\Models\Notebook;
use App\Models\Source;
use App\Models\User;
use App\Sources\EmbedSource;
use Axyr\Langfuse\LangfuseFacade as Langfuse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;
use Tests\TestCase;

/** Gap 5 (no silent states): a failed source can be tried again, by its owner, without deleting it and adding it again. */
class SourceRetryTest extends TestCase
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

    private function source(string $status, array $more = []): Source
    {
        return $this->notebook->sources()->create($more + ['title' => 'S', 'kind' => 'web', 'status' => $status, 'origin_url' => 'http://93.184.216.34/page', 'error' => $status === 'failed' ? 'That page could not be reached.' : null]);
    }

    private function retry(Source $source, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)->post("/notebooks/{$this->notebook->id}/sources/{$source->id}/retry");
    }

    public function test_the_owner_retries_a_failed_source_it_goes_back_to_pending_and_is_queued_again(): void
    {
        Queue::fake();
        $source = $this->source('failed');

        $this->retry($source)->assertRedirect();

        $source->refresh();
        $this->assertSame('pending', $source->status);
        $this->assertNull($source->error);
        Queue::assertPushed(EmbedSource::class, fn ($job) => $job->sourceId === $source->id);
    }

    public function test_only_a_failed_source_can_be_retried(): void
    {
        Queue::fake();
        foreach (['ready', 'pending'] as $status) {
            $source = $this->source($status, ['title' => $status]);

            $this->retry($source)->assertSessionHasErrors('source');

            $this->assertSame($status, $source->fresh()->status);
        }
        Queue::assertNothingPushed();
    }

    public function test_it_is_the_owners_alone_another_user_a_guest_and_a_mismatched_notebook_get_nothing(): void
    {
        Queue::fake();
        $source = $this->source('failed');
        $otherNotebook = Notebook::create(['user_id' => $this->user->id, 'title' => 'Other']);

        $this->retry($source, User::factory()->create())->assertNotFound();
        $this->actingAs($this->user)->post("/notebooks/{$otherNotebook->id}/sources/{$source->id}/retry")->assertNotFound();
        auth()->logout();
        $this->post("/notebooks/{$this->notebook->id}/sources/{$source->id}/retry")->assertRedirect('/login');

        $this->assertSame('failed', $source->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_end_to_end_a_page_that_failed_then_answers_is_read_after_the_retry(): void
    {
        Langfuse::fake();
        Embeddings::fake();
        RouterAgent::fake(fn () => 'A short summary.');
        $html = '<html><body><article><p>'.str_repeat('Harbours and fish at Skagen. ', 30).'</p></article></body></html>';
        Http::fakeSequence('93.184.216.34/*')->pushStatus(500)->push($html, 200, ['Content-Type' => 'text/html']);
        $source = $this->source('pending');

        app()->call([new EmbedSource($source->id), 'handle']);
        $this->assertSame('failed', $source->fresh()->status, 'the first read failed');

        $this->retry($source)->assertRedirect();
        app()->call([new EmbedSource($source->id), 'handle']);

        $source->refresh();
        $this->assertSame('ready', $source->status, (string) $source->error);
        $this->assertStringContainsString('Skagen', (string) $source->content);
        $this->assertGreaterThan(0, $source->passages()->count());
    }

    public function test_the_route_is_named_for_the_interface(): void
    {
        $this->assertSame(url("/notebooks/{$this->notebook->id}/sources/5/retry"), route('sources.retry', [$this->notebook->id, 5]));
    }
}
