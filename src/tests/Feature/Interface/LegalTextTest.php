<?php

namespace Tests\Feature\Interface;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LegalTextTest extends TestCase
{
    use RefreshDatabase;

    /** The body of a docs/legal file: what lies between its two rules. */
    private function keeperBody(string $file): ?string
    {
        $path = base_path("../docs/legal/$file");
        if (! is_file($path)) {
            return null; // the container image holds only src/
        }
        $lines = explode("\n", (string) file_get_contents($path));
        $rules = array_keys(array_filter($lines, fn ($l) => trim($l) === '---'));

        return trim(implode("\n", array_slice($lines, $rules[0] + 1, end($rules) - $rules[0] - 1)))."\n";
    }

    public function test_the_rendered_texts_are_the_keepers_texts(): void
    {
        foreach (['privacy-notice.md' => 'privacy', 'terms.md' => 'terms'] as $docs => $name) {
            $body = $this->keeperBody($docs);
            if ($body === null) {
                $this->markTestSkipped('docs/legal is not in this checkout');
            }
            $this->assertSame($body, (string) file_get_contents(resource_path("legal/$name.md")), "resources/legal/$name.md has drifted from docs/legal/$docs");
        }
    }

    public function test_privacy_shows_every_section_with_the_placeholders_filled_signed_out(): void
    {
        $this->get(route('privacy'))->assertOk()->assertInertia(function (Assert $page) {
            $page->component('privacy')->where('markdown', function ($md) {
                foreach (['## Who is responsible', '## What the demo keeps', '## Why', '## Who else processes your data', '## Please use public documents',
                    '## AI-generated content', '## Cookies and browser storage', '## How long', '## Deleting your data, and your other rights'] as $heading) {
                    $this->assertStringContainsString($heading, $md);
                }
                $this->assertStringNotContainsString('{', $md, 'a placeholder was left unfilled');
                $this->assertStringContainsString('(https://guillermo.tartarus-vault.com/impressum/)', $md);
                $this->assertStringContainsString('(https://guillermo.tartarus-vault.com/datenschutz/)', $md);
                $this->assertStringContainsString('no later than 31 December 2026', $md);
                $this->assertStringNotContainsString('P-1 and P-2', $md, 'the fallback marker must not show');

                return true;
            });
        });
    }

    public function test_the_end_date_comes_from_config(): void
    {
        config(['legal.end_date' => '15 January 2027']);

        $this->get(route('terms'))->assertInertia(fn (Assert $page) => $page->where('parts.1', fn ($p) => str_contains($p, 'by 15 January 2027')));
    }

    public function test_deletion_fallback_wording_swaps_in_when_deletion_is_not_complete(): void
    {
        config(['legal.deletion_complete' => false]);

        $this->get(route('privacy'))->assertInertia(fn (Assert $page) => $page->where('markdown', fn ($md) => str_contains($md, 'he deletes it by hand')
            && ! str_contains($md, 'invitation, at once')));
    }

    public function test_terms_has_three_parts_in_order_with_the_slots_for_the_image_and_the_poems(): void
    {
        $this->get('/terms')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('terms')
            ->where('parts.1', fn ($p) => str_starts_with($p, '## Part 1'))
            ->where('parts.2', fn ($p) => str_starts_with($p, '## Part 2'))
            ->where('parts.3', fn ($p) => str_starts_with($p, '## Part 3') && str_contains($p, '<!-- image') && str_contains($p, '<!-- poems')));
    }

    public function test_the_labels_and_chat_clearing_are_in_the_interface_source(): void
    {
        $js = fn (string $f) => (string) file_get_contents(resource_path("js/$f"));

        $this->assertStringContainsString('AI-generated audio · synthetic voices', $js('components/notebook/audio-player.tsx'));
        $this->assertStringContainsString('AI-generated', $js('components/notebook/report-viewer.tsx'));
        $this->assertStringContainsString('clearChatHistory', $js('components/user-menu-content.tsx'));
        $this->assertStringContainsString('clearChatHistory', $js('components/delete-user.tsx'));
        $this->assertStringContainsString('sent to AI providers and traced in Langfuse', preg_replace('/\s+/', ' ', $js('pages/greeting.tsx')));
    }

    public function test_the_profile_page_tells_a_google_only_user_from_a_password_user(): void
    {
        $google = User::factory()->create(['password' => null]);
        $this->actingAs($google)->get(route('profile.edit'))->assertInertia(fn (Assert $page) => $page->where('hasPassword', false));

        $member = User::factory()->create();
        $this->actingAs($member)->get(route('profile.edit'))->assertInertia(fn (Assert $page) => $page->where('hasPassword', true));

        $dialog = (string) file_get_contents(resource_path('js/components/delete-user.tsx'));
        $this->assertStringContainsString('name="email"', $dialog);
        $this->assertStringContainsString('hasPassword', (string) file_get_contents(resource_path('js/pages/settings/profile.tsx')));
    }

    public function test_no_text_or_layout_of_ours_mentions_resend_or_a_password_reset(): void
    {
        $files = [
            'legal/privacy.md', 'legal/terms.md', 'js/components/site-footer.tsx', 'js/pages/greeting.tsx', 'js/pages/pricing.tsx',
            'js/pages/welcome.tsx', 'js/pages/privacy.tsx', 'js/pages/terms.tsx', 'js/layouts/frame-layout.tsx', 'js/layouts/public-layout.tsx',
            'js/layouts/auth-layout.tsx',
        ];

        foreach ($files as $file) {
            $text = (string) file_get_contents(resource_path($file));
            $this->assertDoesNotMatchRegularExpression('/resend|forgot|password reset|reset link/i', $text, $file);
        }

        $this->get(route('privacy'))->assertInertia(fn (Assert $page) => $page->where('markdown', fn ($md) => stripos($md, 'resend') === false
            && str_contains($md, 'The demo sends no e-mail')));
    }

    public function test_the_sentry_row_shows_only_while_error_monitoring_is_on(): void
    {
        config(['sentry.enabled' => false]);
        $this->get(route('privacy'))->assertInertia(fn (Assert $page) => $page->where('markdown', fn ($md) => stripos($md, 'sentry') === false));

        config(['sentry.enabled' => true]);
        $this->get(route('privacy'))->assertInertia(fn (Assert $page) => $page->where('markdown', fn ($md) => str_contains($md, '| **Sentry** | When something breaks')));
    }

    public function test_the_openai_row_also_shows_while_only_infographics_are_on(): void
    {
        config(['images.enabled' => false, 'infographics.enabled' => true]);
        $this->get(route('privacy'))->assertInertia(fn (Assert $page) => $page->where('markdown', fn ($md) => str_contains($md, '| **OpenAI** |')));

        config(['infographics.enabled' => false]);
        $this->get(route('privacy'))->assertInertia(fn (Assert $page) => $page->where('markdown', fn ($md) => ! str_contains($md, 'OpenAI')));
    }

    public function test_the_openai_row_shows_only_while_generated_icons_are_switched_on(): void
    {
        config(['images.enabled' => true]);
        $this->get(route('privacy'))->assertInertia(fn (Assert $page) => $page->where('markdown', fn ($md) => str_contains($md, '| **OpenAI** | To draw pictures: the title of a new notebook')
            && str_contains($md, '**USA**')));

        config(['images.enabled' => false]);
        $this->get(route('privacy'))->assertInertia(fn (Assert $page) => $page->where('markdown', fn ($md) => ! str_contains($md, 'OpenAI')
            && str_contains($md, '| **Mistral AI** |')
            && str_contains($md, '| **Hetzner** |')));
    }
}
