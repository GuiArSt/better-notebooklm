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

/** D-033: the quiz. Whole questions only; explanation citations validated against the passages shown (as in chat). Fakes only. */
class QuizTest extends TestCase
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

    private function question(int $i, array $override = []): array
    {
        return array_replace([
            'question' => "Question $i?", 'options' => ["A$i", "B$i", "C$i", "D$i"], 'answer' => $i % 4,
            'hint' => "Hint $i", 'explanation' => "Because $i.", 'refs' => ['P'.Passage::firstOrFail()->id],
        ], $override);
    }

    private function quizJson(int $n, array $each = []): string
    {
        return json_encode(['title' => 'Orion', 'questions' => array_map(fn ($i) => $this->question($i, $each), range(1, $n))]);
    }

    private function make(): StudioItem
    {
        return StudioItem::findOrFail($this->actingAs($this->user)->postJson("/notebooks/{$this->notebook->id}/studio", ['kind' => 'quiz'])->assertStatus(202)->json('id'));
    }

    public function test_a_quiz_is_questions_with_four_options_an_answer_a_hint_an_explanation_and_validated_citations(): void
    {
        $id = Passage::firstOrFail()->id;
        RouterAgent::fake(fn () => $this->quizJson(6));

        $item = $this->make();

        $this->assertSame('done', $item->status);
        $this->assertSame('Orion', $item->title);
        $this->assertCount(6, $item->quiz);
        foreach ($item->quiz as $q) {
            $this->assertCount(4, $q['options']);
            $this->assertContains($q['answer'], [0, 1, 2, 3]);
            $this->assertNotSame('', $q['hint']);
            $this->assertNotSame('', $q['explanation']);
            $this->assertSame([$id], $q['refs']);
        }
        $this->assertSame($id, $item->citations[0]['id']);
        $this->assertStringContainsString('Orion Project launched', $item->citations[0]['quote']);
        $this->assertSame(1, UsageEvent::where('prompt_name', 'studio.quiz')->count());

        $this->actingAs($this->user)->getJson("/notebooks/{$this->notebook->id}/studio/{$item->id}")->assertOk()
            ->assertJsonPath('kind', 'quiz')->assertJsonPath('quiz.0.options.3', 'D1')->assertJsonPath('quiz.0.refs.0', $id)->assertJsonPath('settings.kind', 'quiz');
    }

    public function test_a_citation_the_model_invented_is_dropped_and_a_question_left_with_none_is_dropped_with_it(): void
    {
        $good = $this->question(1);
        $inventedOnly = $this->question(2, ['refs' => ['P999999']]);
        $mixed = $this->question(3, ['refs' => ['P999999', $good['refs'][0]]]);
        $rest = array_map(fn ($i) => $this->question($i), range(4, 8));
        RouterAgent::fake(fn () => json_encode(['title' => 'T', 'questions' => array_merge([$good, $inventedOnly, $mixed], $rest)]));

        $item = $this->make();

        $this->assertSame('done', $item->status);
        $this->assertSame(['Question 1?', 'Question 3?', 'Question 4?', 'Question 5?', 'Question 6?', 'Question 7?'], array_slice(array_column($item->quiz, 'question'), 0, 6));
        $this->assertSame([Passage::firstOrFail()->id], $item->quiz[1]['refs'], 'the invented id is gone from the mixed question');
        $this->assertCount(8 - 1, $item->quiz, 'the uncited question left out; capped at eight');
    }

    public function test_a_question_that_is_not_whole_is_left_out_and_text_is_plain(): void
    {
        $questions = [
            $this->question(1, ['question' => 'Plain <b>bold</b>?']),
            $this->question(2, ['options' => ['A', 'B', 'C']]),            // three options
            $this->question(3, ['options' => ['A', 'B', 'C', 'C']]),       // a repeated option
            $this->question(4, ['options' => ['A', 'B', 'C', '']]),        // an empty option
            $this->question(5, ['answer' => 4]),                           // no such option
            $this->question(6, ['answer' => 'c']),                         // a letter is accepted
            $this->question(7), $this->question(8), $this->question(9), $this->question(10),
        ];
        RouterAgent::fake(fn () => json_encode(['title' => 'T', 'questions' => $questions]));

        $quiz = $this->make()->quiz;

        $this->assertSame('Plain bold?', $quiz[0]['question']);
        $this->assertSame('Question 6?', $quiz[1]['question']);
        $this->assertSame(2, $quiz[1]['answer'], 'the letter C means index 2');
        $this->assertCount(6, $quiz);
    }

    public function test_bad_json_or_too_few_whole_questions_fails_cleanly_and_the_ledger_row_stays(): void
    {
        foreach (['I cannot write quizzes.', $this->quizJson(4), json_encode(['title' => 'T', 'questions' => 'nope'])] as $answer) {
            RouterAgent::fake(fn () => $answer);

            $item = $this->make();

            $this->assertSame('failed', $item->status);
            $this->assertSame('The model did not return a usable quiz.', $item->error);
            $this->assertNull($item->quiz);
        }
        $this->assertSame(3, UsageEvent::where('prompt_name', 'studio.quiz')->count(), 'each call that was made is in the ledger');
    }

    public function test_the_parser_accepts_the_bounds_and_caps_the_count(): void
    {
        $gen = app(StudioGenerator::class);
        $id = Passage::firstOrFail()->id;

        $this->assertCount(5, $gen->parseQuiz($this->quizJson(5), [$id], 5, 8)['questions']);
        $this->assertCount(8, $gen->parseQuiz($this->quizJson(12), [$id], 5, 8)['questions']);
        $this->expectExceptionMessage('usable quiz');
        $gen->parseQuiz($this->quizJson(5), [$id + 1], 5, 8); // none of the references was shown to the model
    }

    /** HC-6 (Hipparchus): asked for N, got fewer: the settings say so. */
    public function test_when_fewer_questions_come_back_than_asked_the_settings_record_it(): void
    {
        RouterAgent::fake(fn () => $this->quizJson(6));

        $item = StudioItem::findOrFail($this->actingAs($this->user)->postJson("/notebooks/{$this->notebook->id}/studio", ['kind' => 'quiz', 'options' => ['questions' => 8]])->assertStatus(202)->json('id'));

        $this->assertSame('done', $item->status);
        $this->assertCount(6, $item->quiz);
        $this->assertSame(['asked' => 8, 'got' => 6], $item->settings['delivered']);

        // As asked: nothing to record.
        RouterAgent::fake(fn () => $this->quizJson(5));
        $exact = StudioItem::findOrFail($this->actingAs($this->user)->postJson("/notebooks/{$this->notebook->id}/studio", ['kind' => 'quiz', 'options' => ['questions' => 5]])->json('id'));
        $this->assertArrayNotHasKey('delivered', $exact->settings);
    }
}
