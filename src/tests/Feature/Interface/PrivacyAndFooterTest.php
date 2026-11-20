<?php

namespace Tests\Feature\Interface;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PrivacyAndFooterTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_privacy_page_renders_signed_out(): void
    {
        $this->get(route('privacy'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('privacy'));
    }

    public function test_both_footer_links_are_shared_on_a_public_page(): void
    {
        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('legal.impressum_url', 'https://guillermo.tartarus-vault.com/impressum/')
            ->where('legal.datenschutz_url', 'https://guillermo.tartarus-vault.com/datenschutz/'));
    }

    public function test_the_footer_links_can_be_swapped_in_one_place(): void
    {
        config(['legal.impressum_url' => 'https://example.test/imprint']);

        $this->get(route('privacy'))->assertInertia(fn (Assert $page) => $page->where('legal.impressum_url', 'https://example.test/imprint'));
    }

    public function test_no_personal_name_or_address_is_in_the_legal_config(): void
    {
        $this->assertSame(['impressum_url', 'datenschutz_url', 'end_date', 'deletion_complete'], array_keys(config('legal')));
    }

    public function test_the_terms_page_renders_signed_out_at_exactly_slash_terms(): void
    {
        $this->get('/terms')->assertOk()->assertInertia(fn (Assert $page) => $page->component('terms'));
    }

    public function test_the_footer_links_terms_on_every_layout(): void
    {
        // The footer is one component, used by every layout: it must name /terms next to Impressum and Privacy.
        $footer = (string) file_get_contents(resource_path('js/components/site-footer.tsx'));
        $this->assertStringContainsString('href="/terms"', $footer);

        foreach (['frame-layout', 'public-layout', 'auth-layout'] as $layout) {
            $this->assertStringContainsString('SiteFooter', (string) file_get_contents(resource_path("js/layouts/$layout.tsx")), $layout);
        }
        $this->assertStringContainsString('SiteFooter', (string) file_get_contents(resource_path('js/pages/welcome.tsx')));
    }
}
