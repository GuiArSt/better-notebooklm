<?php

namespace Tests\Feature\Studio;

use App\Ai\Agents\RouterAgent;
use App\Models\Notebook;
use App\Models\Passage;
use App\Models\StudioItem;
use App\Models\UsageEvent;
use App\Models\User;
use App\Sources\EmbedSource;
use App\Studio\StudioGenerator;
use Axyr\Langfuse\LangfuseFacade as Langfuse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;
use Tests\TestCase;

/** D-054: a quiz text is never cut. Up to the hard maximum it is kept as written; beyond it, one repair call, then a clean failure. */
class QuizHardMaxTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Notebook $notebook;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Langfuse::fake();
        Embeddings::fake();
        RouterAgent::fake(fn () => 'A summary.');
        $this->user = User::factory()->create();
        $this->notebook = Notebook::create(['user_id' => $this->user->id, 'title' => 'T']);
        $source = $this->notebook->sources()->create(['title' => 'Orion notes', 'status' => 'pending', 'content' => 'The Orion Project launched in March 2031.']);
        app()->call([new EmbedSource($source->id), 'handle']);
    }

    /** @param  array<string, mixed>  $first  fields of question 1 */
    private function quiz(array $first = [], string $title = 'Orion'): string
    {
        $ref = 'P'.Passage::firstOrFail()->id;
        $questions = array_map(fn ($i) => array_replace([
            'question' => "Question $i?", 'options' => ["A$i", "B$i", "C$i", "D$i"], 'answer' => $i % 4, 'hint' => "Hint $i", 'explanation' => "Because $i.", 'refs' => [$ref],
        ], $i === 1 ? $first : []), range(1, 8));

        return json_encode(['title' => $title, 'questions' => $questions]);
    }

    private function make(): StudioItem
    {
        return StudioItem::findOrFail($this->actingAs($this->user)->postJson("/notebooks/{$this->notebook->id}/studio", ['kind' => 'quiz'])->assertStatus(202)->json('id'));
    }

    public function test_every_text_at_its_hard_maximum_is_kept_uncut_with_no_repair(): void
    {
        $long = fn (int $n) => str_repeat('w', $n - 1).'.';
        RouterAgent::fake(fn () => $this->quiz([
            'question' => $long(600), 'options' => [$long(400), 'B', 'C', 'D'], 'hint' => $long(600), 'explanation' => $long(1000),
        ], $long(200)));

        $item = $this->make();

        $this->assertSame('done', $item->status, (string) $item->error);
        $this->assertSame($long(200), $item->title);
        $this->assertSame($long(600), $item->quiz[0]['question']);
        $this->assertSame($long(400), $item->quiz[0]['options'][0]);
        $this->assertSame($long(600), $item->quiz[0]['hint']);
        $this->assertSame($long(1000), $item->quiz[0]['explanation']);
        $this->assertSame(0, UsageEvent::where('prompt_name', 'studio.quiz.repair')->count());
    }

    public function test_one_over_a_hard_maximum_gets_one_repair_and_the_repaired_text_is_kept(): void
    {
        $calls = 0;
        RouterAgent::fake(function () use (&$calls) {
            $calls++;

            return $calls === 1 ? $this->quiz(['explanation' => str_repeat('e', 1001)]) : $this->quiz(['explanation' => 'Short and right.']);
        });

        $item = $this->make();

        $this->assertSame('done', $item->status, (string) $item->error);
        $this->assertSame(2, $calls);
        $this->assertSame(1, UsageEvent::where('prompt_name', 'studio.quiz.repair')->count());
        $this->assertSame('Short and right.', $item->quiz[0]['explanation']);
    }

    public function test_still_over_after_the_repair_fails_cleanly_and_nothing_is_cut(): void
    {
        RouterAgent::fake(fn () => $this->quiz(['options' => ['A', str_repeat('o', 401), 'C', 'D']]));

        $item = $this->make();

        $this->assertSame('failed', $item->status);
        $this->assertSame('The model did not return a usable quiz.', $item->error);
        $this->assertNull($item->quiz);
        $this->assertSame(1, UsageEvent::where('prompt_name', 'studio.quiz')->count());
        $this->assertSame(1, UsageEvent::where('prompt_name', 'studio.quiz.repair')->count());
    }

    public function test_the_checker_names_each_text_and_ignores_questions_beyond_the_asked_number(): void
    {
        $generator = app(StudioGenerator::class);
        $data = json_decode($this->quiz(['question' => str_repeat('q', 601)], str_repeat('t', 201)), true);
        $data['questions'][7]['hint'] = str_repeat('h', 700); // the 8th, beyond 5 asked: dropped anyway

        $this->assertSame([
            'The title is 201 characters; the limit is 200. Shorten it.',
            'Question 1 is 601 characters; the limit is 600. Shorten it.',
        ], $generator->quizLengthProblems($data, 5));
    }
}
