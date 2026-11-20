<?php

namespace Tests\Feature\Interface;

use App\Frontend\Featured;
use App\Models\Notebook;
use App\Models\User;
use App\Sources\EmbedSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NotebooksTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_lists_only_my_notebooks_and_offers_the_featured_one(): void
    {
        $me = User::factory()->create();
        Notebook::create(['user_id' => $me->id, 'title' => 'Mine', 'emoji' => '🌱']);
        Notebook::create(['user_id' => User::factory()->create()->id, 'title' => 'Theirs']);

        $this->actingAs($me)->get(route('notebooks.index'))->assertInertia(fn (Assert $page) => $page
            ->component('notebooks/index')
            ->has('notebooks', 1)
            ->where('notebooks.0.title', 'Mine')
            ->where('notebooks.0.emoji', '🌱')
            ->where('featured.slug', Featured::SLUG)
            ->has('usage'));
    }

    public function test_create_rename_and_delete_a_notebook(): void
    {
        $me = User::factory()->create();
        $this->actingAs($me);

        $response = $this->post(route('notebooks.store'), ['title' => 'Research', 'emoji' => '🔬']);
        $notebook = Notebook::firstOrFail();
        $response->assertRedirect(route('notebooks.show', $notebook));
        $this->assertSame('🔬', $notebook->emoji);

        $this->patch(route('notebooks.update', $notebook), ['title' => 'Renamed', 'emoji' => '📚'])->assertRedirect();
        $this->assertSame(['Renamed', '📚'], [$notebook->fresh()->title, $notebook->fresh()->emoji]);

        $this->delete(route('notebooks.destroy', $notebook))->assertRedirect(route('notebooks.index'));
        $this->assertDatabaseCount('notebooks', 0);
    }

    public function test_a_blank_create_makes_an_untitled_notebook(): void
    {
        $this->actingAs(User::factory()->create())->post(route('notebooks.store'));

        $this->assertSame(['Untitled notebook', '📓'], [Notebook::first()->title, Notebook::first()->emoji]);
    }

    public function test_nobody_else_can_open_rename_or_delete_my_notebook(): void
    {
        $notebook = Notebook::create(['user_id' => User::factory()->create()->id, 'title' => 'Private']);
        $this->actingAs(User::factory()->create());

        $this->get(route('notebooks.show', $notebook))->assertNotFound();
        $this->patch(route('notebooks.update', $notebook), ['title' => 'x'])->assertNotFound();
        $this->delete(route('notebooks.destroy', $notebook))->assertNotFound();
    }

    public function test_the_notebook_page_carries_sources_notes_and_usage(): void
    {
        $me = User::factory()->create();
        $notebook = Notebook::create(['user_id' => $me->id, 'title' => 'N']);
        $notebook->sources()->create(['kind' => 'text', 'title' => 'S', 'content' => 'abc', 'status' => 'ready']);
        $notebook->notes()->create(['title' => 'T', 'content' => 'c']);

        $this->actingAs($me)->get(route('notebooks.show', $notebook))->assertInertia(fn (Assert $page) => $page
            ->component('notebooks/show')
            ->has('sources', 1)
            ->has('notes', 1)
            ->has('usage.percent'));
    }

    public function test_opening_the_featured_notebook_copies_it_once_and_embeds_its_sources(): void
    {
        Queue::fake();
        $me = User::factory()->create();

        $this->actingAs($me)->post(route('notebooks.featured'));
        $this->post(route('notebooks.featured'));

        $notebook = Notebook::where('featured_slug', Featured::SLUG)->sole();
        $this->assertCount(3, $notebook->sources);
        Queue::assertPushed(EmbedSource::class, 3);
    }

    public function test_the_featured_notebook_does_not_deny_what_the_demo_now_does(): void
    {
        Queue::fake();
        $text = Featured::open(User::factory()->create())->sources->pluck('content')->implode("\n");

        $this->assertStringNotContainsString('slide decks, flashcards, quizzes', $text);
        $this->assertStringNotContainsString('emoji', $text);
        $this->assertStringContainsString('quiz', $text);
        $this->assertStringContainsString('slide decks', $text);
        $this->assertStringContainsString('Stripe test-mode checkout', $text);
    }

    public function test_the_notebook_page_offers_podcast_formats_to_all_and_personas_only_to_who_hears_elevenlabs(): void
    {
        config(['elevenlabs.key' => 'xi-test']);

        foreach ([['free', 0], ['pro', 4]] as [$tier, $personas]) {
            $user = User::factory()->create(['tier' => $tier]);
            $notebook = Notebook::create(['user_id' => $user->id, 'title' => 'N']);

            $this->actingAs($user)->get(route('notebooks.show', $notebook))->assertInertia(fn (Assert $page) => $page
                ->has('podcast.formats', 4)
                ->where('podcast.default_format', 'deep_dive')
                ->where('podcast.brief_length', 'short')
                ->has('podcast.personas', $personas));
        }
    }

    public function test_a_large_source_carries_the_server_numbers_for_the_warning_and_keeps_its_page_ranges_to_itself(): void
    {
        $me = User::factory()->create();
        $notebook = Notebook::create(['user_id' => $me->id, 'title' => 'N']);
        $notebook->sources()->create(['kind' => 'text', 'title' => 'Big', 'content' => 'x', 'status' => 'pending', 'chars' => 250_000]);
        $notebook->sources()->create(['kind' => 'text', 'title' => 'Small', 'content' => 'x', 'status' => 'pending', 'chars' => 900]);

        $this->actingAs($me)->get(route('notebooks.show', $notebook))->assertInertia(fn (Assert $page) => $page
            ->where('sources.0.large', true)
            ->where('sources.0.page_count', 84)
            ->where('sources.0.page_count_estimated', true)
            ->where('sources.0.estimated_cost_micro_usd', fn ($c) => $c > 0)
            ->where('sources.1.large', false)
            ->where('sources.1.estimated_cost_micro_usd', null)
            ->missing('sources.0.pages')
            ->missing('sources.0.chars'));
    }

    public function test_the_featured_notebook_lists_infographics_by_the_servers_switch(): void
    {
        Queue::fake();

        foreach ([false, true] as $on) {
            config(['infographics.enabled' => $on]);
            $text = Featured::open(User::factory()->create())->sources->pluck('content')->implode("\n");

            $this->assertStringNotContainsString('{', $text, 'a placeholder was left unfilled');
            $this->assertSame($on, str_contains($text, 'infographics for pro members'));
            $this->assertSame(! $on, str_contains($text, 'flashcards, infographics, data tables'));
        }
    }

    public function test_the_infographic_tile_is_available_only_where_it_is_on_and_for_pro_reviewer_owner(): void
    {
        $cases = [[false, 'pro', false], [true, 'free', false], [true, 'pro', true]];

        foreach ($cases as [$on, $tier, $available]) {
            config(['infographics.enabled' => $on]);
            $user = User::factory()->create(['tier' => $tier]);
            $notebook = Notebook::create(['user_id' => $user->id, 'title' => 'N']);

            $this->actingAs($user)->get(route('notebooks.show', $notebook))->assertInertia(fn (Assert $page) => $page
                ->where('infographic.available', $available)
                ->has('infographic.styles', 7)
                ->where('infographic.styles.0.label', 'Auto-select')
                ->where('infographic.default_orientation', 'landscape'));
        }

        config(['infographics.enabled' => true]);
        $owner = User::factory()->create(['tier' => 'free', 'role' => 'owner']);
        $this->actingAs($owner)->get(route('notebooks.show', Notebook::create(['user_id' => $owner->id, 'title' => 'N'])))
            ->assertInertia(fn (Assert $page) => $page->where('infographic.available', true));
    }

    public function test_the_featured_card_is_not_shown_again_once_the_user_has_their_copy(): void
    {
        Queue::fake();
        $me = User::factory()->create();

        $this->actingAs($me)->get(route('notebooks.index'))->assertInertia(fn (Assert $page) => $page->where('featured.slug', Featured::SLUG));

        Featured::open($me);

        $this->get(route('notebooks.index'))->assertInertia(fn (Assert $page) => $page
            ->where('featured', null)
            ->where('notebooks.0.title', 'About this demo'));
    }

    public function test_home_and_the_notebook_carry_the_icon_status_for_the_picture(): void
    {
        $me = User::factory()->create();
        $notebook = Notebook::create(['user_id' => $me->id, 'title' => 'N']);
        Notebook::whereKey($notebook->id)->update(['icon_status' => 'done']);

        $this->actingAs($me)->get(route('notebooks.index'))->assertInertia(fn (Assert $page) => $page->where('notebooks.0.icon_status', 'done'));
        $this->get(route('notebooks.show', $notebook))->assertInertia(fn (Assert $page) => $page->where('notebook.icon_status', 'done'));
    }
}
