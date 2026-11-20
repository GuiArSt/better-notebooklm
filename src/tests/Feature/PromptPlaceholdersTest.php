<?php

namespace Tests\Feature;

use App\Ai\Agents\RouterAgent;
use App\Models\Notebook;
use App\Models\StudioItem;
use App\Models\User;
use App\Sources\EmbedSource;
use Axyr\Langfuse\LangfuseFacade as Langfuse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Audio;
use Laravel\Ai\Embeddings;
use Tests\TestCase;

/** HC-5 (Hipparchus): a {{variable}} nobody passed would go to the model as literal text. Every prompt, as its caller really renders it, has none left. */
class PromptPlaceholdersTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_placeholder_survives_in_any_prompt_the_app_sends(): void
    {
        Storage::fake('local');
        Langfuse::fake();
        Embeddings::fake();
        Audio::fake();
        config(['infographics.enabled' => true]);
        RouterAgent::fake(fn () => '{}');
        $user = User::factory()->create(['role' => 'reviewer']);
        $notebook = Notebook::create(['user_id' => $user->id, 'title' => 'T']);
        $source = $notebook->sources()->create(['title' => 'S', 'status' => 'pending', 'content' => 'The Orion Project launched in March 2031 with a budget of four million euros, and it is one of the largest projects in the country.']);

        app()->call([new EmbedSource($source->id), 'handle']); // source.summary and notebook.overview
        foreach (['briefing', 'study_guide', 'faq', 'mindmap', 'quiz', 'slides', 'audio', 'infographic'] as $kind) {
            // '{}' is no valid deck, quiz or script: slides and quiz run their repair path too, and the item fails cleanly. Only the prompts matter here.
            $this->actingAs($user)->postJson("/notebooks/{$notebook->id}/studio", ['kind' => $kind, 'focus' => 'the budget', 'options' => []])->assertStatus(202);
        }
        $this->assertSame(8, StudioItem::count());
        $this->actingAs($user)->post("/notebooks/{$notebook->id}/chat", ['question' => 'When did the project launch?'])->streamedContent();

        RouterAgent::assertNotPrompted(fn ($p) => str_contains($p->agent->instructions(), '{{') || str_contains($p->agent->instructions(), '}}'));
        $this->assertStringNotContainsString('{{', (string) json_encode(Langfuse::getFacadeRoot()->exported()), 'the streamed chat prompt, read from its trace');

        // Not vacuous: the prompts of every family really were rendered and sent.
        foreach (['Summarise the source', 'opening overview', 'briefing document', 'study guide', 'FAQ', 'mind map', 'quiz', 'slide deck', 'podcast-style', 'You wrote a slide deck', 'brief for one infographic', 'You wrote the brief for an infographic'] as $needle) {
            RouterAgent::assertPrompted(fn ($p) => str_contains($p->agent->instructions(), $needle));
        }
    }

    public function test_every_variable_in_every_prompt_file_is_a_known_name(): void
    {
        $known = ['language', 'max_nodes', 'max_turns', 'max_chars', 'min_questions', 'max_questions', 'difficulty', 'min_slides', 'max_slides', 'max_same', 'min_distinct', 'slide_count', 'focus', 'format', 'persona', 'points', 'title_max', 'heading_max', 'figure_max', 'text_max'];

        foreach (glob(resource_path('prompts/*.md')) as $file) {
            preg_match_all('/\{\{(\w+)\}\}/', (string) file_get_contents($file), $m);
            foreach ($m[1] as $name) {
                $this->assertContains($name, $known, basename($file)." uses {{{$name}}}, which no caller passes");
            }
        }
    }
}
