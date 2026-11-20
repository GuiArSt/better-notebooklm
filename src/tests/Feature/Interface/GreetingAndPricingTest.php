<?php

namespace Tests\Feature\Interface;

use App\Auth\GrantPro;
use App\Models\UsageEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GreetingAndPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_user_lands_on_the_greeting_with_their_budget(): void
    {
        $user = User::factory()->create();
        UsageEvent::create(['user_id' => $user->id, 'provider' => 'mistral', 'model' => 'mistral-small-latest', 'kind' => 'chat', 'units' => [], 'cost_micro_usd' => 100_000]);

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('greeting.show'));

        $this->get(route('greeting.show'))->assertInertia(fn (Assert $page) => $page
            ->component('greeting')
            ->where('greeted', false)
            ->where('usage.tier', 'free')
            ->where('usage.spent_usd', 0.1)
            ->has('usage.cap_usd')
            ->where('podcast_usd', fn ($v) => $v > 0 && $v < 1));
    }

    public function test_continuing_is_remembered_and_the_dashboard_then_opens_the_notebooks(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('greeting.store'))->assertRedirect(route('notebooks.index'));
        $this->post(route('greeting.store'))->assertRedirect(route('notebooks.index')); // twice is harmless

        $this->assertDatabaseCount('greetings', 1);
        $this->get(route('dashboard'))->assertRedirect(route('notebooks.index'));
    }

    public function test_guests_cannot_see_the_greeting_or_the_pricing(): void
    {
        $this->get(route('greeting.show'))->assertRedirect(route('login'));
        $this->get(route('pricing'))->assertRedirect(route('login'));
    }

    public function test_pricing_lists_pro_at_cost_times_one_and_a_half(): void
    {
        $this->actingAs(User::factory()->create())->get(route('pricing'))->assertInertia(fn (Assert $page) => $page
            ->component('pricing')
            ->where('markup', 1.5)
            ->where('models.0.model', 'mistral-small-latest')
            ->where('models.0.cost_in', 0.15)
            ->where('models.0.pro_in', 0.225)
            ->where('models.0.pro_out', 0.9));
    }

    public function test_a_reviewer_sees_elevenlabs_price_against_their_own_budget(): void
    {
        config(['elevenlabs.key' => 'test-key']);
        $reviewer = User::factory()->create(['role' => 'reviewer']);

        // 5,000 characters at $0.08 per 1,000 is $0.40, plus the script on the pro chat model.
        $this->actingAs($reviewer)->get(route('greeting.show'))->assertInertia(fn (Assert $page) => $page
            ->where('usage.cap_usd', 25)
            ->where('podcast_usd', fn ($v) => $v >= 0.40 && $v < 0.6));
    }

    public function test_a_free_member_sees_mistrals_price_against_their_own_cap(): void
    {
        config(['elevenlabs.key' => 'test-key']);
        $member = User::factory()->create(['role' => 'member', 'tier' => 'free']);

        // 5,000 characters at $13 per million is $0.065, plus a small script: far below ElevenLabs.
        $this->actingAs($member)->get(route('greeting.show'))->assertInertia(fn (Assert $page) => $page
            ->where('usage.cap_usd', 2)
            ->where('podcast_usd', fn ($v) => $v >= 0.06 && $v < 0.1));
    }

    public function test_a_lapsed_pro_user_is_labelled_and_priced_as_free(): void
    {
        $user = User::factory()->create(['role' => 'member', 'tier' => 'pro', 'pro_until' => now()->subDay()]);

        $this->actingAs($user)->get(route('greeting.show'))->assertInertia(fn (Assert $page) => $page
            ->where('usage.tier', 'free')
            ->where('usage.cap_usd', 2));
    }

    public function test_pricing_shows_a_speech_price_for_each_tier_with_the_pro_markup(): void
    {
        $this->actingAs(User::factory()->create())->get(route('pricing'))->assertInertia(fn (Assert $page) => $page
            ->has('speech', 2)
            ->where('speech.0.tier', 'free')
            ->where('speech.0.voice', 'Mistral')
            ->where('speech.0.cost_per_1k_chars', 0.013)
            ->where('speech.0.pro_per_1k_chars', 0.0195)
            ->where('speech.1.tier', 'pro')
            ->where('speech.1.voice', 'ElevenLabs')
            ->where('speech.1.cost_per_1k_chars', 0.08)
            ->where('speech.1.pro_per_1k_chars', 0.12)
            ->where('speech.1.cost_per_episode', 0.4)
            ->where('speech.1.pro_per_episode', 0.6));
    }

    public function test_the_pricing_page_reads_its_three_plans_from_config(): void
    {
        config(['billing.secret' => 'sk_test_x', 'legal.end_date' => '1 February 2027']);

        $this->actingAs(User::factory()->create(['role' => 'member', 'tier' => 'free']))->get(route('pricing'))->assertInertia(fn (Assert $page) => $page
            ->component('pricing')
            ->where('tier', 'free')
            ->where('stripe_ready', true)
            ->where('end_date', '1 February 2027')
            ->where('plans.free.cap', 2)
            ->where('plans.free.chat_model', config('models.chat.free.model'))
            ->where('plans.pro.chat_model', config('models.chat.pro.model'))
            ->where('plans.pro.price', 5)
            ->where('plans.pro.days', GrantPro::DAYS)
            ->where('example_chat.questions', 20)
            ->where('example_chat.free_usd', fn ($v) => $v > 0)
            ->where('example_chat.pro_usd', fn ($v) => $v > 0));
    }

    public function test_the_checkout_return_banner_only_takes_known_values(): void
    {
        $this->actingAs(User::factory()->create());

        foreach (['success', 'cancelled', 'unavailable'] as $state) {
            $this->get(route('pricing', ['checkout' => $state]))->assertInertia(fn (Assert $page) => $page->where('checkout', $state));
        }
        $this->get(route('pricing', ['checkout' => '<script>']))->assertInertia(fn (Assert $page) => $page->where('checkout', null));
    }

    public function test_the_pricing_page_has_the_upgrade_button_the_test_card_and_no_planned_line(): void
    {
        $page = (string) file_get_contents(resource_path('js/pages/pricing.tsx'));

        $this->assertStringContainsString('Upgrade to Pro (test mode)', $page);
        $this->assertStringContainsString('/billing/checkout', $page);
        $this->assertStringContainsString('4242 4242 4242 4242', $page);
        $this->assertStringContainsString('any future date, any CVC', preg_replace('/\s+/', ' ', $page));
        $this->assertStringNotContainsString('(planned)', $page);
        $this->assertStringContainsString('not in this demo', $page);
    }

    public function test_the_pricing_page_says_the_allowance_is_raw_cost_and_the_markup_is_only_a_list_price(): void
    {
        $page = preg_replace('/\s+/', ' ', (string) file_get_contents(resource_path('js/pages/pricing.tsx')));

        $this->assertStringContainsString('of model use a month while Pro is active', $page);
        $this->assertStringContainsString('Two purchases in one month still give', $page);
        $this->assertStringNotContainsString('per test purchase)', str_replace('days per test purchase)', '', $page));
        $this->assertStringNotContainsString('Recommended for reviewers', $page);
        $this->assertStringContainsString('List price: our cost × {markup}', $page);
        $this->assertStringContainsString('illustration only', $page);
        $this->assertStringContainsString('at our raw cost', $page);
        $this->assertStringNotContainsString('budget at cost', $page);
        $this->assertStringNotContainsString('Models at cost', $page);
    }

    public function test_the_example_chat_shows_raw_cost_as_what_pro_uses_and_the_markup_as_list_price(): void
    {
        $this->actingAs(User::factory()->create())->get(route('pricing'))->assertInertia(fn (Assert $page) => $page
            ->where('example_chat.pro_list_usd', fn ($list) => $list > 0)
            ->where('example_chat.pro_usd', fn ($raw) => $raw > 0)
            ->where('example_chat', fn ($c) => abs($c['pro_list_usd'] - round($c['pro_usd'] * 1.5, 3)) < 0.0011));
    }

    public function test_a_reviewer_and_the_owner_see_their_own_budget_not_the_free_plan_as_theirs(): void
    {
        foreach (['reviewer', 'owner'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get(route('pricing'))->assertInertia(fn (Assert $page) => $page
                ->where('usage.label', $role)
                ->where('usage.cap_usd', $role === 'owner' ? 50 : 25));
        }
        $this->assertStringContainsString('special-account', (string) file_get_contents(resource_path('js/pages/pricing.tsx')));
    }

    public function test_the_usage_label_follows_the_role_caps_config_not_a_second_list(): void
    {
        config(['ledger.role_caps' => ['tester' => 2_000_000]]);

        $this->actingAs(User::factory()->create(['role' => 'tester']))->get(route('pricing'))->assertInertia(fn (Assert $page) => $page
            ->where('usage.label', 'tester'));
        $this->actingAs(User::factory()->create(['role' => 'owner']))->get(route('pricing'))->assertInertia(fn (Assert $page) => $page
            ->where('usage.label', fn ($label) => $label !== 'owner'));
    }

    public function test_the_greeting_does_not_call_live_things_not_in_this_demo(): void
    {
        $source = (string) file_get_contents(resource_path('js/pages/greeting.tsx'));
        $notIn = substr($source, (int) strpos($source, 'const NOT_IN_DEMO'), 400);

        foreach (['slides', 'quizzes', 'Quiz'] as $live) {
            $this->assertStringNotContainsString($live, $notIn, "$live is live, so it cannot be listed as not in the demo");
        }
        $this->assertStringContainsString('Quizzes and slide decks', $source);

        foreach ([false, true] as $on) {
            config(['infographics.enabled' => $on]);
            $this->actingAs(User::factory()->create())->get(route('greeting.show'))->assertInertia(fn (Assert $page) => $page->where('infographics', $on));
        }
    }
}
