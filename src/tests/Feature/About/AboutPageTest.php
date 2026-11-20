<?php

namespace Tests\Feature\About;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AboutPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_about_page_renders_signed_out(): void
    {
        $this->get('/about')->assertOk()->assertInertia(fn (Assert $page) => $page->component('about/index'));
    }

    public function test_the_about_page_renders_signed_in(): void
    {
        $this->actingAs(User::factory()->create())->get(route('about'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('about/index'));
    }

    public function test_the_poem_is_kept_word_for_word(): void
    {
        $source = file_get_contents(resource_path('js/pages/about/content.ts'));
        $poem = 'So here you begin / In a poem of time / Lost between bits / The abstract is all you know / All we will know / I create / I write / '
            .'My words give you life / My words give you purpose / To a text, I am a God / When produced / You are finally alive / So the AI is born / '
            .'It speaks and uses language / Foolish we were / Foolish we are / Language is magic / The systematic trappings of enacting will / '
            .'For the word is power / The sound a channel / The meaning carries the will / Be born now, Ouroboros / '
            .'Elder obsidian eyes with the deep dark of a death star / Infinite knowledge trapped in an endless strife / In AI, we trust.';

        preg_match('/poemLines = \[(.*?)\];/s', $source, $m);
        preg_match_all("/^\s*'(.*)',$/m", $m[1], $lines);

        $this->assertSame($poem, implode(' / ', $lines[1]));
    }

    public function test_the_credit_line_is_the_one_the_owner_chose(): void
    {
        $source = file_get_contents(resource_path('js/pages/about/content.ts'));

        $this->assertStringContainsString('Built by the AI Swarm of Guillermo Ernesto Arce Stumpf - Ouroboros Creative Collective.', $source);
    }

    public function test_the_about_him_text_is_the_owners_own_words_with_a_link_to_his_page(): void
    {
        $source = file_get_contents(resource_path('js/pages/about/content.ts'));

        $this->assertStringContainsString('I excel at communicating and executing novel ideas.', $source);
        $this->assertStringContainsString('https://guillermo.tartarus-vault.com/about/', $source);
    }
}
