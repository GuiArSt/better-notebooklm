<?php

namespace Tests\Feature\Studio;

use App\Ai\Agents\RouterAgent;
use App\Models\Notebook;
use App\Models\StudioItem;
use App\Models\UsageEvent;
use App\Models\User;
use App\Sources\EmbedSource;
use App\Studio\GenerateStudioItem;
use App\Studio\Podcast;
use App\Studio\StudioGenerator;
use Axyr\Langfuse\LangfuseFacade as Langfuse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Audio;
use Laravel\Ai\Embeddings;
use Tests\TestCase;

/** D-039: the four pro personas replace host B. Pro, reviewer and owner may choose one; free is refused. A persona's voice, bible, guardrails and tags are code. */
class PodcastPersonaTest extends TestCase
{
    use RefreshDatabase;

    private function notebookFor(User $user): Notebook
    {
        Storage::fake('local');
        Langfuse::fake();
        Embeddings::fake();
        Audio::fake();
        RouterAgent::fake(fn () => 'A summary.');
        $notebook = Notebook::create(['user_id' => $user->id, 'title' => 'T']);
        $source = $notebook->sources()->create(['title' => 'S', 'content' => 'A fact worth hearing about.', 'status' => 'pending']);
        app()->call([new EmbedSource($source->id), 'handle']);

        return $notebook;
    }

    private function send(User $user, Notebook $notebook, string $kind, array $options)
    {
        return $this->actingAs($user)->postJson("/notebooks/{$notebook->id}/studio", ['kind' => $kind, 'options' => $options]);
    }

    private function pro(): User
    {
        return User::factory()->create(['role' => 'member', 'tier' => 'pro', 'pro_until' => now()->addDay()]);
    }

    public function test_a_persona_is_refused_on_free_and_allowed_for_pro_reviewer_and_owner(): void
    {
        $free = User::factory()->create(['role' => 'member', 'tier' => 'free']);
        $this->send($free, $this->notebookFor($free), 'audio', ['persona' => 'dez'])->assertStatus(422)->assertJsonValidationErrors(['options.persona']);
        $lapsed = User::factory()->create(['role' => 'member', 'tier' => 'pro', 'pro_until' => now()->subDay()]);
        $this->send($lapsed, $this->notebookFor($lapsed), 'audio', ['persona' => 'dez'])->assertStatus(422);

        foreach ([$this->pro(), User::factory()->create(['role' => 'reviewer']), User::factory()->create(['role' => 'owner'])] as $user) {
            $item = StudioItem::findOrFail($this->send($user, $this->notebookFor($user), 'audio', ['persona' => 'rosa'])->assertStatus(202)->json('id'));
            $this->assertSame('rosa', $item->settings['options']['persona']);
        }
    }

    public function test_an_unknown_persona_or_a_persona_on_another_kind_is_refused(): void
    {
        $user = $this->pro();
        $notebook = $this->notebookFor($user);

        $this->send($user, $notebook, 'audio', ['persona' => 'a-real-person'])->assertStatus(422);
        $this->send($user, $notebook, 'quiz', ['persona' => 'dez'])->assertStatus(422);
        $this->send($user, $notebook, 'briefing', ['persona' => 'dez'])->assertStatus(422);
    }

    public function test_the_estimate_refuses_a_persona_on_free_too(): void
    {
        $free = User::factory()->create(['role' => 'member', 'tier' => 'free']);
        $notebook = $this->notebookFor($free);

        $this->actingAs($free)->getJson("/notebooks/{$notebook->id}/studio/estimate?kind=audio&options[persona]=dez")->assertStatus(422);
    }

    public function test_each_persona_puts_its_bible_and_the_guardrails_in_the_script_prompt(): void
    {
        $user = $this->pro();
        $notebook = $this->notebookFor($user);
        $names = ['dez' => 'Dez Calloway', 'alistair' => 'Sir Alistair Fenwick-Hale', 'varga' => 'Professor Ines Varga', 'rosa' => 'Rosa Delgado'];
        $this->assertSame(array_keys($names), Podcast::personas());

        foreach ($names as $persona => $name) {
            RouterAgent::fake(fn () => '{}');
            $this->send($user, $notebook, 'audio', ['persona' => $persona])->assertStatus(202);
            RouterAgent::assertPrompted(fn ($p) => str_contains($p->agent->instructions(), "Persona for host B: $name")
                && str_contains($p->agent->instructions(), 'Accents are voices, not jokes')
                && str_contains($p->agent->instructions(), 'No real people'));
        }
    }

    public function test_without_a_persona_there_is_no_persona_text_and_no_placeholder(): void
    {
        $user = $this->pro();
        RouterAgent::fake(fn () => '{}');
        $this->send($user, $this->notebookFor($user), 'audio', []);

        RouterAgent::assertPrompted(fn ($p) => ! str_contains($p->agent->instructions(), 'Persona for host B') && ! str_contains($p->agent->instructions(), '{{'));
    }

    public function test_every_persona_has_a_bible_a_stock_voice_and_a_line(): void
    {
        $voices = config('elevenlabs.persona_voices');
        foreach (Podcast::personas() as $persona) {
            $this->assertFileExists(resource_path("prompts/personas/$persona.md"));
            $this->assertNotEmpty($voices[$persona] ?? null, "$persona has a voice");
            $this->assertNotEmpty(config("podcast.personas.$persona.line"));
        }
        $this->assertSame(Podcast::personas(), array_keys($voices));
        $this->assertCount(4, array_unique($voices), 'four personas, four voices');
        $this->assertNotContains(config('elevenlabs.voices.a'), $voices, 'the steady host keeps a voice of its own');
    }

    public function test_every_tag_a_persona_names_is_on_the_allowed_list(): void
    {
        foreach (Podcast::personas() as $persona) {
            preg_match_all('/\[(\w+)\]/', (string) file_get_contents(resource_path("prompts/personas/$persona.md")), $m);
            $this->assertNotEmpty($m[1], "$persona names its tags");
            $this->assertSame([], array_diff($m[1], config('elevenlabs.tags')), "$persona names a tag the code would strip");
        }
    }

    public function test_an_unknown_tag_is_stripped_and_at_most_one_allowed_tag_is_kept_per_turn(): void
    {
        $tags = ['laughs', 'sighs'];

        $this->assertSame('So the lock is the word bakery.', Podcast::stripTags('So the lock is the word bakery. [giggles]', $tags));
        $this->assertSame('Ha. [laughs] The lock.', Podcast::stripTags('Ha. [laughs] The lock.', $tags));
        $this->assertSame('[sighs] Fine. The lock.', Podcast::stripTags('[sighs] Fine. [laughs] The lock.', $tags), 'the first tag stays, a second one goes');
        $this->assertSame('Plain text.', Podcast::stripTags('Plain [laughs] text.', []), 'no allowed tags: all go');
        $this->assertSame('Keep [1] this.', Podcast::stripTags('Keep [1] this.', ['1']), 'only the allowed list decides');
    }

    public function test_the_script_keeps_allowed_tags_for_a_persona_and_strips_every_other(): void
    {
        $script = json_encode(['title' => 'T', 'turns' => [
            ['speaker' => 'A', 'text' => 'Hello there. [pause]'],
            ['speaker' => 'B', 'text' => 'Well. [laughs] Then [explosion] this.'],
        ]]);
        $generator = app(StudioGenerator::class);

        $withPersona = $generator->parseScript($script, 10, 1000, config('elevenlabs.tags'));
        $this->assertSame('Hello there. [pause]', $withPersona['turns'][0]['text']);
        $this->assertSame('Well. [laughs] Then this.', $withPersona['turns'][1]['text']);

        $without = $generator->parseScript($script, 10, 1000);
        $this->assertSame('Hello there.', $without['turns'][0]['text']);
        $this->assertSame('Well. Then this.', $without['turns'][1]['text']);
    }

    public function test_the_persona_speaks_with_its_own_voice_in_place_of_host_b_and_it_is_recorded(): void
    {
        config(['elevenlabs.key' => 'xi-test', 'elevenlabs.voices.a' => 'voice-host-a', 'elevenlabs.voices.b' => 'voice-host-b', 'elevenlabs.persona_voices.alistair' => 'voice-alistair']);
        Http::fake(['api.elevenlabs.io/*' => Http::response('MP3', 200)]);
        $user = User::factory()->create(['role' => 'reviewer']);
        $notebook = $this->notebookFor($user);
        RouterAgent::fake(fn () => json_encode(['title' => 'T', 'turns' => [
            ['speaker' => 'A', 'text' => 'What is it?'], ['speaker' => 'B', 'text' => 'The display. [pause] Watch.'],
        ]]));

        $item = StudioItem::findOrFail($this->send($user, $notebook, 'audio', ['persona' => 'alistair'])->assertStatus(202)->json('id'))->fresh();

        $this->assertSame('done', $item->status, (string) $item->error);
        $this->assertSame(['A' => 'voice-host-a', 'B' => 'voice-alistair'], $item->settings['models']['speech']['voices']);
        Http::assertSent(fn (Request $r) => $r->data()['inputs'][0]['voice_id'] === 'voice-host-a'
            && $r->data()['inputs'][1]['voice_id'] === 'voice-alistair'
            && $r->data()['inputs'][1]['text'] === 'The display. [pause] Watch.');
    }

    public function test_mistral_never_hears_a_tag(): void
    {
        config(['elevenlabs.key' => null]); // no ElevenLabs: Mistral's voice reads the script
        $user = $this->pro();
        $notebook = $this->notebookFor($user);
        RouterAgent::fake(fn () => json_encode(['title' => 'T', 'turns' => [['speaker' => 'A', 'text' => 'One.'], ['speaker' => 'B', 'text' => 'Two. [laughs]']]]));

        $this->send($user, $notebook, 'audio', ['persona' => 'dez'])->assertStatus(202);

        Audio::assertGenerated(fn ($a) => str_contains((string) $a->text, 'Two.') && ! str_contains((string) $a->text, '['));
    }

    public function test_make_it_again_refuses_a_persona_once_pro_has_lapsed(): void
    {
        $user = $this->pro();
        $notebook = $this->notebookFor($user);
        $first = StudioItem::findOrFail($this->send($user, $notebook, 'audio', ['persona' => 'dez'])->assertStatus(202)->json('id'));
        $user->forceFill(['pro_until' => now()->subDay()])->save();

        $this->actingAs($user->fresh())->postJson("/notebooks/{$notebook->id}/studio/{$first->id}/regenerate")->assertStatus(422)->assertJsonValidationErrors(['options.persona']);
    }

    public function test_an_item_whose_owner_lost_pro_fails_plainly_instead_of_speaking_with_a_persona(): void
    {
        $user = User::factory()->create(['role' => 'member', 'tier' => 'free']);
        $notebook = $this->notebookFor($user);
        $item = StudioItem::create(['notebook_id' => $notebook->id, 'user_id' => $user->id, 'kind' => 'audio', 'title' => 'A', 'status' => 'pending', 'settings' => ['options' => ['persona' => 'dez']]]);

        GenerateStudioItem::dispatchSync($item->id, 'media');

        $item->refresh();
        $this->assertSame('failed', $item->status);
        $this->assertSame(Podcast::PERSONA_REFUSAL, $item->error);
        $this->assertSame(0, UsageEvent::where('studio_item_id', $item->id)->count(), 'nothing was spent');
    }

    public function test_the_item_records_the_labels_how_this_was_made_shows(): void
    {
        $user = $this->pro();
        $notebook = $this->notebookFor($user);

        $withPersona = StudioItem::findOrFail($this->send($user, $notebook, 'audio', ['format' => 'critique', 'persona' => 'rosa'])->assertStatus(202)->json('id'));
        $plain = StudioItem::findOrFail($this->send($user, $notebook, 'audio', [])->assertStatus(202)->json('id'));
        $report = StudioItem::findOrFail($this->send($user, $notebook, 'briefing', [])->assertStatus(202)->json('id'));

        $this->assertSame(['format' => 'Critique', 'persona' => ['name' => 'Rosa Delgado', 'role' => 'The mother of five']], $withPersona->settings['podcast']);
        $this->assertSame(['format' => 'Deep Dive', 'persona' => null], $plain->settings['podcast'], 'the default format is named, not left to be guessed');
        $this->assertArrayNotHasKey('podcast', $report->settings);
    }

    public function test_make_it_again_keeps_format_and_persona(): void
    {
        $user = $this->pro();
        $notebook = $this->notebookFor($user);
        $first = StudioItem::findOrFail($this->send($user, $notebook, 'audio', ['format' => 'debate', 'persona' => 'varga', 'length' => 'short'])->assertStatus(202)->json('id'));

        $again = StudioItem::findOrFail($this->actingAs($user)->postJson("/notebooks/{$notebook->id}/studio/{$first->id}/regenerate")->assertStatus(202)->json('id'));

        $this->assertEquals(['format' => 'debate', 'persona' => 'varga', 'length' => 'short'], $again->settings['options']);
    }
}
