<?php

namespace Tests\Feature\Interface;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Every link of the frame, the user menu, the notebook and the footer has a target that opens. */
class NavigationTest extends TestCase
{
    use RefreshDatabase;

    private function js(string $file): string
    {
        return (string) file_get_contents(resource_path("js/$file"));
    }

    public function test_the_logo_and_the_home_link_go_to_the_notebooks(): void
    {
        $frame = $this->js('layouts/frame-layout.tsx');
        $this->assertMatchesRegularExpression('/<Link href="\/notebooks" className="flex items-center gap-2">/', $frame, 'the logo');
        $this->assertStringNotContainsString('dashboard', $frame);
        $public = $this->js('layouts/public-layout.tsx');
        $this->assertStringContainsString('if (auth.user) {', $public, 'signed in, the public pages wear the whole frame');
        $this->assertStringContainsString('<FrameLayout>{children}</FrameLayout>', $public);
    }

    public function test_the_header_and_menu_name_every_destination_and_each_target_opens(): void
    {
        $frame = $this->js('layouts/frame-layout.tsx');
        $menu = $this->js('components/user-menu-content.tsx');
        $footer = $this->js('components/site-footer.tsx');

        foreach (['/notebooks', '/pricing', '/settings/profile'] as $href) {
            $this->assertStringContainsString("href=\"$href\"", $frame, "header link $href");
        }
        $this->assertStringContainsString('Sign out', $menu);
        $this->assertStringContainsString('Settings', $menu);
        $this->assertStringContainsString('href="/admin/users"', $menu, 'the owner reaches Users on a phone too');
        $this->assertStringContainsString('/privacy', $footer);
        $this->assertStringContainsString('/terms', $footer);
        $this->assertStringContainsString('impressum_url', $footer);

        $user = User::factory()->create();
        $this->actingAs($user);
        foreach (['/notebooks', '/pricing', '/settings/profile', '/privacy', '/terms'] as $path) {
            $this->get($path)->assertOk();
        }
        $this->post('/logout')->assertRedirect();
        $this->assertGuest();
    }

    public function test_the_owner_sees_a_users_link_and_it_opens(): void
    {
        $this->assertStringContainsString('href="/admin/users"', $this->js('layouts/frame-layout.tsx'));

        $this->actingAs(User::factory()->create(['role' => 'owner']))->get('/admin/users')->assertOk();
    }

    public function test_settings_pages_use_the_same_frame_so_the_way_home_is_always_there(): void
    {
        $app = $this->js('app.tsx');
        $this->assertStringContainsString('[FrameLayout, SettingsLayout]', $app);
        $this->assertStringNotContainsString('AppLayout', $app);
    }

    public function test_a_notebook_has_a_visible_way_back_to_the_notebooks(): void
    {
        $show = $this->js('pages/notebooks/show.tsx');
        $this->assertStringContainsString('href="/notebooks"', $show);
        $this->assertStringContainsString('All notebooks', $show);
    }

    public function test_the_dashboard_url_lands_on_the_notebooks_after_the_greeting(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('greeting.store'));

        $this->get('/dashboard')->assertRedirect(route('notebooks.index'));
    }

    public function test_the_greyed_home_pills_say_not_in_this_demo(): void
    {
        $home = (string) file_get_contents(resource_path('js/pages/notebooks/index.tsx'));

        $this->assertStringContainsString("['Discover', 'Collections']", $home);
        $this->assertStringContainsString('not in this demo', $home);
    }
}
