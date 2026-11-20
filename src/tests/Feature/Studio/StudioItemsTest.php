<?php

namespace Tests\Feature\Studio;

use App\Ai\Agents\RouterAgent;
use App\Models\Notebook;
use App\Models\StudioItem;
use App\Models\UsageEvent;
use App\Models\User;
use App\Sources\EmbedSource;
use Axyr\Langfuse\LangfuseFacade as Langfuse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;
use Tests\TestCase;

/** The Studio list (brief 2026-11-18): how an item was made, rename, make it again, what it cost. Fakes only. */
class StudioItemsTest extends TestCase
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
        RouterAgent::fake(fn () => '## Briefing text');
        $this->user = User::factory()->create();
        $this->notebook = Notebook::create(['user_id' => $this->user->id, 'title' => 'T']);
        $source = $this->notebook->sources()->create(['title' => 'Orion notes', 'content' => 'The Orion Project launched in March 2031 with a budget of 4.2 million euros.', 'status' => 'pending']);
        app()->call([new EmbedSource($source->id), 'handle']);
    }

    private function make(string $kind = 'briefing')
    {
        return $this->actingAs($this->user)->postJson("/notebooks/{$this->notebook->id}/studio", ['kind' => $kind]);
    }

    public function test_the_settings_are_recorded_when_the_item_is_made_and_the_cost_once_it_is_settled(): void
    {
        $id = $this->make()->assertStatus(202)->json('id');
        $item = StudioItem::findOrFail($id);

        $this->assertSame('briefing', $item->settings['kind']);
        $this->assertSame([['id' => $this->notebook->sources()->first()->id, 'title' => 'Orion notes']], $item->settings['sources']);
        $this->assertSame('free', $item->settings['tier']);
        $this->assertSame('mistral-small-latest', $item->settings['models']['text']['model']);
        $this->assertNull($item->settings['instructions']);
        $this->assertSame('auto', $item->settings['language']);

        $this->assertGreaterThan(0, $item->settings['cost_micro_usd']);
        $this->assertSame($item->settings['cost_micro_usd'], (int) UsageEvent::where('studio_item_id', $id)->sum('cost_micro_usd'));

        $json = $this->actingAs($this->user)->getJson("/notebooks/{$this->notebook->id}/studio/$id")->json();
        $this->assertSame(1, $json['sources_count']);
        $this->assertArrayHasKey('created_at', $json);
        $this->assertArrayHasKey('settings', $json);
        $this->assertNull($json['duration_seconds']);
    }

    public function test_an_audio_item_records_the_voices_and_the_model_of_speech(): void
    {
        Queue::fake();
        $item = StudioItem::findOrFail($this->make('audio')->assertStatus(202)->json('id'));

        $this->assertArrayHasKey('speech', $item->settings['models']);
        $this->assertSame(['A', 'B'], array_keys($item->settings['models']['speech']['voices']));
    }

    public function test_old_items_keep_null_settings_and_still_show(): void
    {
        $old = StudioItem::create(['notebook_id' => $this->notebook->id, 'user_id' => $this->user->id, 'kind' => 'faq', 'title' => 'FAQ', 'status' => 'done', 'content' => 'x']);

        $json = $this->actingAs($this->user)->getJson("/notebooks/{$this->notebook->id}/studio/{$old->id}")->assertOk()->json();

        $this->assertNull($json['settings']);
        $this->assertNull($json['sources_count']);
    }

    public function test_the_owner_can_rename_and_the_title_is_validated_and_plain_text(): void
    {
        $item = StudioItem::findOrFail($this->make()->json('id'));
        $url = "/notebooks/{$this->notebook->id}/studio/{$item->id}";

        $this->actingAs($this->user)->patchJson($url, ['title' => '  Orion <b>launch</b> notes '])->assertOk()->assertJsonPath('title', 'Orion launch notes');
        $this->assertSame('Orion launch notes', $item->fresh()->title);

        $this->actingAs($this->user)->patchJson($url, ['title' => ''])->assertStatus(422);
        $this->actingAs($this->user)->patchJson($url, ['title' => '<i></i>'])->assertStatus(422);
        $this->actingAs($this->user)->patchJson($url, ['title' => str_repeat('x', 121)])->assertStatus(422);
        $this->actingAs($this->user)->patchJson($url, [])->assertStatus(422);
        $this->assertSame('Orion launch notes', $item->fresh()->title);
    }

    public function test_another_user_cannot_rename_or_regenerate(): void
    {
        $item = StudioItem::findOrFail($this->make()->json('id'));
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->patchJson("/notebooks/{$this->notebook->id}/studio/{$item->id}", ['title' => 'Mine now'])->assertNotFound();
        $this->actingAs($stranger)->postJson("/notebooks/{$this->notebook->id}/studio/{$item->id}/regenerate")->assertNotFound();
        $this->assertSame('Briefing', $item->fresh()->title);
    }

    public function test_make_it_again_copies_the_settings_charges_once_and_keeps_the_old_item(): void
    {
        $first = StudioItem::findOrFail($this->make()->json('id'));
        $events = UsageEvent::where('kind', 'chat')->count();

        $new = StudioItem::findOrFail($this->actingAs($this->user)->postJson("/notebooks/{$this->notebook->id}/studio/{$first->id}/regenerate")->assertStatus(202)->json('id'));

        $this->assertNotSame($first->id, $new->id);
        $this->assertSame($first->settings['sources'], $new->settings['sources']);
        $this->assertSame($first->settings['models'], $new->settings['models']);
        $this->assertSame($first->id, $new->settings['regenerated_from']);
        $this->assertSame('done', $new->status);
        $this->assertSame($events + 1, UsageEvent::where('kind', 'chat')->count(), 'one more model call, charged once');
        $this->assertNotNull($first->fresh(), 'the old item stays');
        $this->assertSame(2, StudioItem::count());
    }

    public function test_make_it_again_is_refused_by_the_cap_like_any_other_item(): void
    {
        $first = StudioItem::findOrFail($this->make()->json('id'));
        config(['ledger.user_caps.free' => 500_000]); // a small cap of this test's own, whatever the default
        UsageEvent::create(['user_id' => $this->user->id, 'provider' => 'x', 'model' => 'x', 'kind' => 'chat', 'units' => [], 'cost_micro_usd' => 500_000]);

        $new = StudioItem::findOrFail($this->actingAs($this->user)->postJson("/notebooks/{$this->notebook->id}/studio/{$first->id}/regenerate")->assertStatus(202)->json('id'));

        $this->assertSame('failed', $new->status);
        $this->assertStringContainsString('limit', (string) $new->error);
        $this->assertSame(0, (int) $new->settings['cost_micro_usd']);
    }

    public function test_make_it_again_works_for_an_item_made_before_settings_existed(): void
    {
        $old = StudioItem::create(['notebook_id' => $this->notebook->id, 'user_id' => $this->user->id, 'kind' => 'briefing', 'title' => 'Briefing', 'status' => 'done']);

        $new = StudioItem::findOrFail($this->actingAs($this->user)->postJson("/notebooks/{$this->notebook->id}/studio/{$old->id}/regenerate")->assertStatus(202)->json('id'));

        $this->assertSame('briefing', $new->settings['kind']);
        $this->assertSame($old->id, $new->settings['regenerated_from']);
    }

    /** HB-19 (Argus's backend review): a kind added only in config does not crash, and the "in flight" message follows the config number. */
    public function test_a_kind_added_only_in_config_gets_a_name_and_the_in_flight_message_follows_the_config(): void
    {
        Queue::fake();
        config(['studio.kinds' => [...config('studio.kinds'), 'study_poster']]);

        $item = StudioItem::findOrFail($this->make('study_poster')->assertStatus(202)->json('id'));
        $this->assertSame('Study Poster', $item->title);

        $this->assertSame('Briefing', StudioItem::findOrFail($this->make('briefing')->json('id'))->title, 'a named kind keeps its configured name');

        // Two in flight (the queue is faked, so they stay pending): the third is refused with the number from config.
        $this->make('faq')->assertStatus(429)->assertJsonPath('message', 'Two items are already being made. Wait for one to finish.')
            ->json();
        config(['studio.max_in_flight' => 3]);
        StudioItem::create(['notebook_id' => $this->notebook->id, 'user_id' => $this->user->id, 'kind' => 'faq', 'title' => 'x', 'status' => 'pending']);
        $this->make('faq')->assertStatus(429)->assertJsonPath('message', 'Three items are already being made. Wait for one to finish.');
        config(['studio.max_in_flight' => 1]);
        $this->make('faq')->assertStatus(429)->assertJsonPath('message', 'One item is already being made. Wait for one to finish.');
    }
}
