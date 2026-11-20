<?php

namespace Tests\Feature;

use App\Ai\Agents\RouterAgent;
use App\Chat\CitationValidator;
use App\Chat\Retriever;
use App\Models\Notebook;
use App\Models\Passage;
use App\Models\Source;
use App\Models\UsageEvent;
use App\Models\User;
use App\Sources\Chunker;
use App\Sources\EmbedSource;
use Axyr\Langfuse\LangfuseFacade as Langfuse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;
use Tests\TestCase;

class CitedChatTest extends TestCase
{
    use RefreshDatabase;

    private const TEXT = "The Orion Project launched in March 2031 with a budget of 4.2 million euros.\n\nIts lead engineer, Ada Okafor, moved the team to Lisbon in 2033.\n\nThe cafeteria serves lunch from noon until two.";

    private function sse(string $body): array
    {
        return collect(explode("\n\n", trim($body)))
            ->map(fn ($chunk) => json_decode(substr($chunk, 6), true))->all();
    }

    public function test_paste_a_source_the_job_runs_a_question_streams_and_ends_with_a_real_citation(): void
    {
        Langfuse::fake();
        Embeddings::fake();
        RouterAgent::fake(fn () => 'A summary.');
        $user = User::factory()->create();
        $notebook = Notebook::create(['user_id' => $user->id, 'title' => 'T']);

        // 1. Paste a source: stored at once, embedded by the queued job.
        Queue::fake();
        $this->actingAs($user)->post("/notebooks/{$notebook->id}/sources", ['title' => 'Orion notes', 'content' => self::TEXT])->assertRedirect();
        Queue::assertPushed(EmbedSource::class);
        $source = Source::firstOrFail();
        $this->assertSame('pending', $source->status);

        app()->call([new EmbedSource($source->id), 'handle']);

        $source->refresh();
        $this->assertSame('ready', $source->status);
        $passage = Passage::where('source_id', $source->id)->orderBy('position')->first();
        $this->assertNotNull($passage->embedding);
        $this->assertSame($passage->content, mb_substr(self::TEXT, $passage->start_offset, $passage->end_offset - $passage->start_offset));

        // 2. Ask: the (faked) model cites the passage by id and also invents P999.
        RouterAgent::fake(["The project launched in March 2031 [P{$passage->id}], and it was funded by aliens [P999]."]);

        $response = $this->actingAs($user)->post("/notebooks/{$notebook->id}/chat", ['question' => 'When did Orion launch?']);
        $response->assertOk();
        $events = $this->sse($response->streamedContent());

        // 3. The stream: text first, then one citations event, then done.
        $text = collect($events)->where('type', 'text')->pluck('delta')->implode('');
        $this->assertStringContainsString('March 2031', $text);
        $this->assertSame('done', end($events)['type']);
        $citations = collect($events)->firstWhere('type', 'citations')['citations'];

        $this->assertCount(1, $citations);
        $this->assertSame($passage->id, $citations[0]['id']);
        $this->assertSame('Orion notes', $citations[0]['source_title']);
        $this->assertSame($passage->start_offset, $citations[0]['start']);
        $this->assertSame($passage->end_offset, $citations[0]['end']);
        $this->assertSame(mb_substr(self::TEXT, $citations[0]['start'], $citations[0]['end'] - $citations[0]['start']), $citations[0]['quote']);

        // 4. The invented id is dropped and recorded as a Langfuse score.
        $this->assertSame(1, count(Langfuse::getFacadeRoot()->scores()));
        $this->assertSame('citation_dropped', Langfuse::getFacadeRoot()->scores()[0]->name);
        $this->assertStringContainsString('P999', (string) Langfuse::getFacadeRoot()->scores()[0]->comment);

        // 5. The chat call went through the door: a ledger row, a trace id.
        $this->assertTrue(UsageEvent::where('kind', 'chat')->whereNotNull('langfuse_trace_id')->exists());
    }

    public function test_an_unchecked_source_is_not_answered_from_and_its_ids_are_dropped(): void
    {
        Langfuse::fake();
        Embeddings::fake();
        RouterAgent::fake(fn () => 'A summary.');
        $user = User::factory()->create();
        $notebook = Notebook::create(['user_id' => $user->id, 'title' => 'T']);
        $source = $notebook->sources()->create(['title' => 'S', 'content' => self::TEXT, 'status' => 'pending']);
        app()->call([new EmbedSource($source->id), 'handle']);
        $passage = Passage::first();

        $this->actingAs($user)->patch("/notebooks/{$notebook->id}/sources/{$source->id}", ['checked' => false])->assertRedirect();
        RouterAgent::fake(["x [P{$passage->id}]"]);

        $events = $this->sse($this->actingAs($user)->post("/notebooks/{$notebook->id}/chat", ['question' => 'q'])->streamedContent());

        $this->assertSame(0, UsageEvent::where('prompt_name', 'chat.answer')->count(), 'the chat model was never called');
        $this->assertSame([], collect($events)->firstWhere('type', 'citations')['citations']);
    }

    public function test_ids_written_in_groups_are_validated_one_by_one_against_the_passages_shown(): void
    {
        Langfuse::fake();
        Embeddings::fake();
        RouterAgent::fake(fn () => 'A summary.');
        config(['retrieval.chunk_chars' => 60]);
        $user = User::factory()->create();
        $notebook = Notebook::create(['user_id' => $user->id, 'title' => 'T']);
        $source = $notebook->sources()->create(['title' => 'S', 'content' => self::TEXT, 'status' => 'pending']);
        app()->call([new EmbedSource($source->id), 'handle']);
        [$a, $b] = Passage::orderBy('position')->take(2)->pluck('id')->all();
        $validator = app(CitationValidator::class);

        // A group mixing two real ids and an invented one: the invented id is dropped, the real ones come back (R1-3 holds in groups).
        $out = $validator->resolve("Both [P$a, P$b, P999999] and, as models write it, [P$a, $b].", $notebook, null, [$a, $b]);
        $this->assertSame([$a, $b], array_column($out['citations'], 'id'));
        $this->assertSame([999999], $out['dropped']);

        // An id that exists in the notebook but was not shown to the model is dropped inside a group, too.
        $out = $validator->resolve("Seen [P$a, P$b].", $notebook, null, [$a]);
        $this->assertSame([$a], array_column($out['citations'], 'id'));
        $this->assertSame([$b], $out['dropped']);

        // Brackets that are not citations are left alone.
        $this->assertSame([], CitationValidator::idsIn('Years [2031, 2033] and [12] and [see P5 above].'));
    }

    public function test_another_users_notebook_is_not_reachable(): void
    {
        $notebook = Notebook::create(['user_id' => User::factory()->create()->id, 'title' => 'T']);

        $this->actingAs(User::factory()->create())->post("/notebooks/{$notebook->id}/chat", ['question' => 'q'])->assertNotFound();
        $this->actingAs(User::factory()->create())->get("/notebooks/{$notebook->id}")->assertNotFound();
    }

    public function test_the_chunker_keeps_exact_offsets_and_splits_long_paragraphs(): void
    {
        $text = "Erste Zeile über Käse.\n\n".str_repeat('Wort ', 500)."\n\nSchluss.";
        $chunks = app(Chunker::class)->chunk($text, 300);

        $this->assertGreaterThan(3, count($chunks));
        foreach ($chunks as $c) {
            $this->assertSame($c['text'], mb_substr($text, $c['start'], $c['end'] - $c['start']));
            $this->assertLessThanOrEqual(300, mb_strlen($c['text']));
        }
        $this->assertSame(0, $chunks[0]['start']);
    }

    public function test_large_notebooks_use_hybrid_retrieval_and_find_the_exact_name(): void
    {
        Langfuse::fake();
        Embeddings::fake();
        RouterAgent::fake(fn () => 'A summary.');
        config(['retrieval.whole_budget_tokens' => 10, 'retrieval.chunk_chars' => 120, 'retrieval.top_k' => 3]);
        $user = User::factory()->create();
        $notebook = Notebook::create(['user_id' => $user->id, 'title' => 'T']);
        $filler = collect(range(1, 12))->map(fn ($i) => "Paragraph number $i talks about gardens and weather in some detail.")->implode("\n\n");
        $source = $notebook->sources()->create(['title' => 'S', 'content' => $filler."\n\nThe harbour master is Zbigniew Kowalczyk-Ng.", 'status' => 'pending']);
        app()->call([new EmbedSource($source->id), 'handle']);
        $needle = Passage::where('content', 'like', '%Zbigniew%')->firstOrFail();

        $found = app(Retriever::class)->context($user, $notebook, 'Who is Zbigniew Kowalczyk-Ng?');

        $this->assertLessThanOrEqual(3, $found->count());
        $this->assertTrue($found->contains('id', $needle->id), 'full-text side must find the exact name');
        $this->assertTrue(UsageEvent::where('kind', 'embed')->count() >= 2, 'the question was embedded through the door');
    }

    public function test_small_notebooks_send_everything_whole_in_order(): void
    {
        Langfuse::fake();
        Embeddings::fake();
        RouterAgent::fake(fn () => 'A summary.');
        $user = User::factory()->create();
        $notebook = Notebook::create(['user_id' => $user->id, 'title' => 'T']);
        $source = $notebook->sources()->create(['title' => 'S', 'content' => self::TEXT, 'status' => 'pending']);
        config(['retrieval.chunk_chars' => 80]);
        app()->call([new EmbedSource($source->id), 'handle']);
        $embedsBefore = UsageEvent::where('kind', 'embed')->count();

        $found = app(Retriever::class)->context($user, $notebook, 'anything');

        $this->assertSame(Passage::count(), $found->count());
        $this->assertSame($embedsBefore, UsageEvent::where('kind', 'embed')->count(), 'whole path does not embed the question');
        $this->assertSame($found->pluck('position')->sort()->values()->all(), $found->pluck('position')->all());
    }
}
