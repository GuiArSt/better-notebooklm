<?php

namespace Tests\Feature\Interface;

use Tests\TestCase;

/** Glyph's final set is wired in: favicons, manifest, backgrounds, studio icons, no starter-kit leftovers. */
class BrandAssetsTest extends TestCase
{
    public function test_every_icon_file_the_page_head_names_exists_and_is_ours_not_laravels(): void
    {
        foreach (['favicon.svg', 'favicon.ico', 'apple-touch-icon.png', 'icon-192.png', 'icon-512.png', 'icon-maskable-512.png', 'site.webmanifest'] as $file) {
            $this->assertFileExists(public_path($file), $file);
        }
        $this->assertStringNotContainsString('FF2D20', strtoupper((string) file_get_contents(public_path('favicon.svg'))), 'the Laravel favicon is gone');
        $this->assertSame([192, 192], array_slice(getimagesize(public_path('icon-192.png')), 0, 2));
        $this->assertSame([512, 512], array_slice(getimagesize(public_path('icon-maskable-512.png')), 0, 2));

        $head = (string) file_get_contents(resource_path('views/app.blade.php'));
        foreach (['/favicon.ico', '/favicon.svg', '/apple-touch-icon.png', '/site.webmanifest', 'name="theme-color"'] as $needle) {
            $this->assertStringContainsString($needle, $head, $needle);
        }
    }

    public function test_the_manifest_names_the_app_and_its_three_icons(): void
    {
        $manifest = json_decode((string) file_get_contents(public_path('site.webmanifest')), true);

        $this->assertSame('Better NotebookLM', $manifest['name']);
        $this->assertCount(3, $manifest['icons']);
        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
        }
        $this->assertContains('maskable', array_column($manifest['icons'], 'purpose'));
    }

    public function test_the_constellation_backgrounds_and_the_brand_marks_are_in_public(): void
    {
        foreach (['sparse', 'dense'] as $kind) {
            foreach (['light', 'dark'] as $mode) {
                $this->assertFileExists(public_path("brand/bg/constellation-$kind-$mode.svg"));
            }
        }
        foreach (['mark.svg', 'mark-ink.svg', 'mark-white.svg', 'lockup-horizontal.svg', 'lockup-horizontal-dark.svg'] as $file) {
            $this->assertFileExists(public_path("brand/$file"));
        }
        $css = (string) file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('constellation-sparse-light.svg', $css);
        $this->assertStringContainsString('constellation-dense-dark.svg', $css);
    }

    public function test_the_studio_uses_our_nine_icons_and_the_starter_links_are_gone(): void
    {
        $icons = (string) file_get_contents(resource_path('js/components/studio-icons.tsx'));
        foreach (['Audio', 'Video', 'Mindmap', 'Reports', 'Flashcards', 'Quiz', 'Infographic', 'Slides', 'Table'] as $name) {
            $this->assertStringContainsString("function {$name}Icon", $icons);
        }
        $this->assertStringNotContainsString('lucide', (string) file_get_contents(resource_path('js/components/studio-icons.tsx')));
        $panel = (string) file_get_contents(resource_path('js/components/notebook/studio-panel.tsx'));
        $this->assertStringNotContainsString('AudioLines', $panel);
        $this->assertStringNotContainsString('BrainCircuit', $panel);

        foreach (['components/app-header.tsx', 'components/app-sidebar.tsx'] as $file) {
            $this->assertFileDoesNotExist(resource_path("js/$file"), 'the starter-kit shell is gone (HF-15)');
        }
        $mark = (string) file_get_contents(resource_path('js/components/app-logo-icon.tsx'));
        $this->assertStringContainsString('M8.5 5H5v14h3.5', $mark, 'the logo is mark-3C');
    }

    public function test_dialogs_keep_their_content_inside_the_box_and_the_source_cards_wrap(): void
    {
        $dialog = (string) file_get_contents(resource_path('js/components/ui/dialog.tsx'));
        $this->assertStringContainsString('grid-cols-[minmax(0,1fr)]', $dialog, 'a long field cannot widen a dialog');

        $add = (string) file_get_contents(resource_path('js/components/notebook/add-source-dialog.tsx'));
        $this->assertStringContainsString('grid-cols-1 gap-2 sm:grid-cols-3', $add);
        $this->assertStringContainsString('sm:grid-cols-4', $add);
        $this->assertStringContainsString('min-w-0', $add);
    }
}
