<?php

namespace Tests\Feature\Studio;

use App\Ai\Agents\RouterAgent;
use App\Models\Notebook;
use App\Models\StudioItem;
use App\Models\User;
use App\Sources\EmbedSource;
use App\Studio\Podcast;
use Axyr\Langfuse\LangfuseFacade as Langfuse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Audio;
use Laravel\Ai\Embeddings;
use Tests\TestCase;

/** D-039: the podcast's formats. A format changes the structure of the script, never its length; the length is code (config/podcast.php). */
class PodcastFormatTest extends TestCase
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
        Audio::fake();
        RouterAgent::fake(fn () => 'A summary.');
        $this->user = User::factory()->create();
        $this->notebook = Notebook::create(['user_id' => $this->user->id, 'title' => 'T']);
        $source = $this->notebook->sources()->create(['title' => 'Orion notes', 'content' => 'The Orion Project launched in March 2031 with a budget of four million euros.', 'status' => 'pending']);
        app()->call([new EmbedSource($source->id), 'handle']);
    }

    private function audio(array $options): StudioItem
    {
        RouterAgent::fake(fn () => '{}'); // no usable script: the item fails after the prompt was sent, and only the prompt matters here
        $response = $this->actingAs($this->user)->postJson("/notebooks/{$this->notebook->id}/studio", ['kind' => 'audio', 'options' => $options]);

        return StudioItem::findOrFail($response->assertStatus(202)->json('id'));
    }

    public function test_each_format_puts_its_own_text_in_the_script_prompt(): void
    {
        $marks = ['deep_dive' => 'Format: Deep Dive.', 'brief' => 'Format: Brief.', 'critique' => 'Format: Critique.', 'debate' => 'Format: Debate.'];
        $this->assertSame(array_keys($marks), Podcast::formats());

        foreach ($marks as $format => $mark) {
            $this->audio(['format' => $format]);
            RouterAgent::assertPrompted(fn ($p) => str_contains($p->agent->instructions(), $mark));
        }
    }

    public function test_no_format_means_deep_dive(): void
    {
        $item = $this->audio([]);

        RouterAgent::assertPrompted(fn ($p) => str_contains($p->agent->instructions(), 'Format: Deep Dive.'));
        $this->assertArrayNotHasKey('format', $item->settings['options'], 'what the user did not choose is not recorded as chosen; the default is code');
    }

    public function test_the_length_sets_the_script_limits_and_brief_starts_short(): void
    {
        $this->assertSame(['turns' => 10, 'chars' => 2_000], Podcast::limits(['length' => 'short']));
        $this->assertSame(['turns' => 17, 'chars' => 3_500], Podcast::limits([]));
        $this->assertSame(['turns' => 24, 'chars' => 5_000], Podcast::limits(['length' => 'long']));
        $this->assertSame(['turns' => 10, 'chars' => 2_000], Podcast::limits(['format' => 'brief']));
        $this->assertSame(['turns' => 24, 'chars' => 5_000], Podcast::limits(['format' => 'brief', 'length' => 'long']));
        $this->assertSame(Podcast::limits([]), Podcast::limits(['format' => 'critique']), 'a format other than Brief does not change the length');
        $this->assertLessThanOrEqual(config('studio.audio_max_chars'), config('podcast.lengths.long.chars'), 'no episode is longer than the cap');
        $this->assertLessThanOrEqual(config('studio.audio_max_turns'), config('podcast.lengths.long.turns'));

        $this->audio(['format' => 'debate', 'length' => 'short']);
        RouterAgent::assertPrompted(fn ($p) => str_contains($p->agent->instructions(), 'At most 10 turns and at most 2000 characters'));
    }

    public function test_an_unknown_format_or_length_is_refused(): void
    {
        foreach ([['format' => 'monologue'], ['length' => 'epic'], ['format' => ['debate']]] as $options) {
            $this->actingAs($this->user)->postJson("/notebooks/{$this->notebook->id}/studio", ['kind' => 'audio', 'options' => $options])->assertStatus(422);
        }
    }

    public function test_a_format_belongs_to_audio_only(): void
    {
        $this->actingAs($this->user)->postJson("/notebooks/{$this->notebook->id}/studio", ['kind' => 'quiz', 'options' => ['format' => 'debate']])->assertStatus(422);
    }

    public function test_make_it_again_keeps_the_format(): void
    {
        $first = $this->audio(['format' => 'critique', 'length' => 'long']);
        $again = StudioItem::findOrFail($this->actingAs($this->user)->postJson("/notebooks/{$this->notebook->id}/studio/{$first->id}/regenerate")->assertStatus(202)->json('id'));

        $this->assertEquals(['format' => 'critique', 'length' => 'long'], $again->settings['options']);
    }

    public function test_the_estimate_follows_the_length_of_the_format(): void
    {
        $cost = fn (array $options) => $this->actingAs($this->user)->getJson("/notebooks/{$this->notebook->id}/studio/estimate?".http_build_query(['kind' => 'audio', 'options' => $options]))
            ->assertOk()->json('estimate_micro_usd');

        $this->assertLessThan($cost([]), $cost(['format' => 'brief']), 'Brief starts short, so it is estimated cheaper than the default');
        $this->assertSame($cost(['length' => 'short']), $cost(['format' => 'brief']));
    }
}
