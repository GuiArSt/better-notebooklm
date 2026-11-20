<?php

namespace Tests\Feature;

use App\Ai\Agents\RouterAgent;
use App\Ai\Language;
use App\Models\Notebook;
use App\Models\Passage;
use App\Models\Source;
use App\Models\StudioItem;
use App\Models\User;
use App\Sources\EmbedSource;
use App\Sources\SourceSummary;
use App\Studio\StudioGenerator;
use Axyr\Langfuse\LangfuseFacade as Langfuse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;
use Tests\TestCase;

/** D-035: generated text follows the sources' language unless one is chosen; chat follows the question. The prompt NAMES the language. */
class LanguageTest extends TestCase
{
    use RefreshDatabase;

    private const EN = 'The Orion Project launched in March 2031 with a budget of four million euros. Its lead engineer moved the team to Lisbon in 2033, and the project is now one of the largest of its kind in the country.';

    private const FR = "Le projet Orion a été lancé en mars 2031 avec un budget de quatre millions d'euros. Son ingénieur en chef a déplacé l'équipe à Lisbonne en 2033, et le projet est maintenant l'un des plus grands du pays dans son domaine.";

    private const DE = 'Das Projekt Orion wurde im März 2031 mit einem Budget von vier Millionen Euro gestartet. Der leitende Ingenieur hat das Team 2033 nach Lissabon verlegt, und das Projekt ist heute eines der größten des Landes.';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Langfuse::fake();
        Embeddings::fake();
    }

    private function notebookWith(string $text, ?User $user = null): array
    {
        $user ??= User::factory()->create();
        $notebook = Notebook::create(['user_id' => $user->id, 'title' => 'T']);
        $source = $notebook->sources()->create(['title' => 'S', 'status' => 'pending', 'content' => $text]);

        return [$user, $notebook, $source];
    }

    public function test_the_guess_names_the_language_of_clear_text_and_nothing_when_unsure(): void
    {
        $this->assertSame('English', Language::guess(self::EN));
        $this->assertSame('French', Language::guess(self::FR));
        $this->assertSame('German', Language::guess(self::DE));
        $this->assertSame('English', Language::guess('What does the guide say about the first steps?'), 'a short question is enough');
        $this->assertSame('French', Language::guess('Que dit le guide sur les premières étapes ?'));
        $this->assertNull(Language::guess('Orion 2031 Lisbon'), 'too few words');
        $this->assertNull(Language::guess(''));
        $this->assertNull(Language::guess('Zxqv blorp wibble snarf quux grault waldo fred plugh xyzzy thud nurp'), 'no known words');
    }

    /** LA-1 (Argus): a close relative the app does not know is not forced into Spanish or Portuguese. */
    public function test_catalan_and_galician_are_not_called_spanish_or_portuguese(): void
    {
        $catalan = 'El projecte Orion es va llançar al març del 2031 amb un pressupost de quatre milions d\'euros. Els enginyers van traslladar l\'equip a Lisboa, i és un dels projectes més grans del país, també per això els seus resultats són molt coneguts.';
        $galician = 'O proxecto Orion lanzouse en marzo de 2031 cunha orzamento de catro millóns de euros. Os enxeñeiros trasladaron o equipo a Lisboa, e é unha das máis grandes iniciativas do país, tamén polo que coa súa xente moi preparada.';

        $this->assertNull(Language::guess($catalan));
        $this->assertNull(Language::guess($galician));
        $this->assertSame('Spanish', Language::guess('El proyecto Orion se lanzó en marzo de 2031 con un presupuesto de cuatro millones de euros. Los ingenieros trasladaron el equipo a Lisboa, y es uno de los proyectos más grandes del país.'));
        $this->assertSame('Portuguese', Language::guess('O projeto Orion foi lançado em março de 2031 com um orçamento de quatro milhões de euros. Os engenheiros mudaram a equipe para Lisboa, e é um dos maiores projetos do país, com uma equipe muito preparada.'));
        $this->assertSame('the language of the passages', Language::forPrompt(null, $catalan), 'unknown: the fallback wording, not a wrong name');
    }

    public function test_for_prompt_takes_the_chosen_language_first_then_the_texts_own_then_the_fallback(): void
    {
        $this->assertSame('French', Language::forPrompt('fr', self::EN));
        $this->assertSame('Klingon', Language::forPrompt('Klingon', self::EN), 'an unlisted name is passed through');
        $this->assertSame('English', Language::forPrompt('auto', self::EN));
        $this->assertSame('English', Language::forPrompt(null, self::EN));
        $this->assertSame('the language of the passages', Language::forPrompt(null, 'x'));
    }

    public function test_english_sources_are_summarised_and_overviewed_in_english_whatever_the_browsers_language(): void
    {
        RouterAgent::fake(fn () => '{"summary": "A summary.", "questions": ["One?", "Two?", "Three?"]}');
        [$user, $notebook, $source] = $this->notebookWith(self::EN);

        // The browser says French: nothing in the app reads it.
        $this->actingAs($user)->withHeaders(['Accept-Language' => 'fr-FR,fr;q=0.9'])->get('/notebooks')->assertOk();
        $this->assertSame('en', app()->getLocale());
        app()->call([new EmbedSource($source->id), 'handle']);

        RouterAgent::assertPrompted(fn ($p) => str_contains($p->agent->instructions(), 'in English, the language of the source'));
        RouterAgent::assertPrompted(fn ($p) => str_contains($p->agent->instructions(), 'in English, the language of the sources'));
        RouterAgent::assertNotPrompted(fn ($p) => str_contains($p->agent->instructions(), 'French'));
    }

    public function test_french_sources_get_a_french_summary_named_as_such(): void
    {
        RouterAgent::fake(fn () => 'Un résumé.');
        [, , $source] = $this->notebookWith(self::FR);

        app()->call([new EmbedSource($source->id), 'handle']);

        RouterAgent::assertPrompted(fn ($p) => str_contains($p->agent->instructions(), 'in French, the language of the source'));
    }

    public function test_a_chat_answer_follows_the_language_of_the_question_not_of_the_passages(): void
    {
        RouterAgent::fake(fn () => 'A summary.');
        [$user, $notebook, $source] = $this->notebookWith(self::EN);
        app()->call([new EmbedSource($source->id), 'handle']);
        RouterAgent::fake(fn () => 'Antwort [P1]');

        // A streamed call is traced with its system prompt: read the instruction the model was given from the trace.
        $ask = function (string $question): string {
            $this->actingAs(User::first())->post('/notebooks/'.Notebook::first()->id.'/chat', ['question' => $question])->streamedContent();
            $trace = json_encode(Langfuse::getFacadeRoot()->exported());
            preg_match_all('/Answer in ([A-Za-z ]+), the language of the user/', (string) $trace, $m);

            return end($m[1]) ?: '';
        };

        $this->assertSame('German', $ask('Wann wurde das Projekt gestartet und was ist das Budget?'));
        $this->assertSame('English', $ask('When did the project launch and what is the budget?'));
        $this->assertSame('the language of the question', $ask('Orion?'), 'too short to call: the model is told to follow the question');
    }

    public function test_studio_items_write_in_the_chosen_language_or_the_sources_own(): void
    {
        RouterAgent::fake(fn () => 'A summary.');
        [$user, $notebook, $source] = $this->notebookWith(self::EN);
        app()->call([new EmbedSource($source->id), 'handle']);
        RouterAgent::fake(fn () => '## Briefing');

        $make = fn (array $settings) => StudioItem::findOrFail($this->actingAs($user)->postJson("/notebooks/{$notebook->id}/studio", ['kind' => 'briefing'] + $settings)->assertStatus(202)->json('id'));

        // Automatic: the sources' own language, named.
        $make([]);
        RouterAgent::assertPrompted(fn ($p) => str_contains($p->agent->instructions(), 'briefing document about the passages') && str_contains($p->agent->instructions(), 'Write in English.'));
        $this->assertNotNull(Passage::first());

        // The generator also honours a language stored in the settings (D-035's Customize step).
        $item = StudioItem::create(['notebook_id' => $notebook->id, 'user_id' => $user->id, 'kind' => 'briefing', 'title' => 'B', 'status' => 'pending', 'settings' => ['language' => 'fr']]);
        app(StudioGenerator::class)->generate($item);
        RouterAgent::assertPrompted(fn ($p) => str_contains($p->agent->instructions(), 'in French.'));
    }

    // ---- the language is told in code, stored on the source, and checked ---------------------------------------------

    public function test_the_language_of_a_source_is_detected_stored_and_named_to_the_model(): void
    {
        $prompts = [];
        RouterAgent::fake(function (string $prompt) use (&$prompts) {
            $prompts[] = $prompt;

            return 'A summary in English.';
        });
        [, , $source] = $this->notebookWith(self::EN);

        app()->call([new EmbedSource($source->id), 'handle']);

        $this->assertSame('en', $source->fresh()->language);
        RouterAgent::assertPrompted(fn ($p) => str_contains($p->agent->instructions(), 'Write in English') || str_contains($p->agent->instructions(), 'in English, the language of the source'));
        RouterAgent::assertNotPrompted(fn ($p) => str_contains($p->agent->instructions(), 'in the language of the source.'));
    }

    public function test_a_summary_in_the_wrong_language_is_asked_again_firmly_and_the_second_one_is_kept(): void
    {
        $answers = [self::FR, self::EN, '{"summary": "'.self::EN.'", "questions": ["One?", "Two?", "Three?"]}'];
        $seen = [];
        RouterAgent::fake(function (string $prompt) use (&$answers, &$seen) {
            $seen[] = $prompt;

            return array_shift($answers) ?? self::EN;
        });
        [, , $source] = $this->notebookWith(self::EN);

        app()->call([new EmbedSource($source->id), 'handle']);

        $this->assertSame(self::EN, $source->fresh()->summary);
        $this->assertStringContainsString('MUST be written in English only', $seen[1]);
    }

    public function test_the_overview_names_the_majority_language_of_the_sources(): void
    {
        RouterAgent::fake(fn () => '{"summary": "'.self::EN.'", "questions": ["a?", "b?", "c?"]}');
        $user = User::factory()->create();
        $notebook = Notebook::create(['user_id' => $user->id, 'title' => 'T']);
        foreach ([self::EN, self::EN, self::FR] as $i => $text) {
            $notebook->sources()->create(['title' => "S$i", 'status' => 'ready', 'content' => $text, 'language' => Language::guessCode($text), 'summary' => 'x']);
        }

        app(SourceSummary::class)->overview($user, $notebook);

        RouterAgent::assertPrompted(fn ($p) => str_contains($p->agent->instructions(), 'in English, the language of the sources'));
    }

    public function test_studio_auto_uses_the_stored_majority_language_of_the_chosen_sources(): void
    {
        RouterAgent::fake(fn () => '## Briefing');
        $user = User::factory()->create();
        $notebook = Notebook::create(['user_id' => $user->id, 'title' => 'T']);
        foreach ([[self::FR, 'fr'], [self::FR, 'fr'], [self::EN, 'en']] as $i => [$text, $code]) {
            $s = $notebook->sources()->create(['title' => "S$i", 'status' => 'pending', 'content' => $text]);
            app()->call([new EmbedSource($s->id), 'handle']);
            $this->assertSame($code, $s->fresh()->language);
        }

        $this->actingAs($user)->postJson("/notebooks/{$notebook->id}/studio", ['kind' => 'briefing'])->assertStatus(202);

        RouterAgent::assertPrompted(fn ($p) => str_contains($p->agent->instructions(), 'briefing document about the passages') && str_contains($p->agent->instructions(), 'Write in French.'));
    }

    public function test_the_resummarise_command_rewrites_only_summaries_in_another_language(): void
    {
        RouterAgent::fake(fn () => 'An English summary of the notebook.');
        $user = User::factory()->create();
        $notebook = Notebook::create(['user_id' => $user->id, 'title' => 'T']);
        $wrong = $notebook->sources()->create(['title' => 'W', 'status' => 'ready', 'content' => self::EN, 'summary' => self::FR]);
        $right = $notebook->sources()->create(['title' => 'R', 'status' => 'ready', 'content' => self::EN, 'summary' => self::EN]);
        $none = $notebook->sources()->create(['title' => 'N', 'status' => 'ready', 'content' => self::EN]);

        $this->artisan('sources:resummarise')->assertFailed();
        $this->artisan('sources:resummarise', ['--mismatched' => true])->expectsOutputToContain('3 source(s) checked, 1 summary(ies) rewritten')->assertSuccessful();

        $this->assertSame('An English summary of the notebook.', $wrong->fresh()->summary);
        $this->assertSame(self::EN, $right->fresh()->summary);
        $this->assertNull($none->fresh()->summary);
        $this->assertSame('en', $none->fresh()->language, 'the language is stored for every ready source');
    }

    /** HC-12 (Hipparchus): a retry that does not help, and an unreadable overview, are logged with ids only. */
    public function test_a_summary_still_in_the_wrong_language_after_the_retry_and_an_unreadable_overview_are_logged(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function ($e) use (&$logged) {
            $logged[] = $e->message.json_encode($e->context);
        });
        RouterAgent::fake(fn () => self::FR);
        [$user, $notebook, $source] = $this->notebookWith(self::EN);

        app()->call([new EmbedSource($source->id), 'handle']);

        $log = implode("\n", $logged);
        $this->assertStringContainsString('Summary kept in another language than its source', $log);
        $this->assertStringContainsString('"source_id":'.$source->id, $log);
        $this->assertStringContainsString('Overview kept in another language than its sources', $log);
        $this->assertStringContainsString('Overview answer could not be read', $log);
        $this->assertStringNotContainsString('Orion', $log, 'no text, only ids');
    }
}
