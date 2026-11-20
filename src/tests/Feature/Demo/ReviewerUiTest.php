<?php

namespace Tests\Feature\Demo;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The reviewer UI (D-059) is decided in the browser from auth.user.role and greets by auth.user.name
 * (resources/js/demo/flag.ts, use-demo.ts). If either stops reaching the page, the welcome, the checklist
 * and the winks vanish without a word: these tests make that loud.
 */
class ReviewerUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_greeting_tells_the_page_the_user_is_a_reviewer_and_their_name(): void
    {
        $reviewer = User::factory()->create(['role' => 'reviewer', 'name' => 'Lena Prüfer']);

        $this->actingAs($reviewer)->get(route('greeting.show'))->assertInertia(fn (Assert $page) => $page
            ->component('greeting')
            ->where('auth.user.role', 'reviewer')
            ->where('auth.user.name', 'Lena Prüfer')
            ->has('usage.cap_usd'));
    }

    public function test_the_home_page_carries_the_role_too_so_the_checklist_can_show(): void
    {
        $reviewer = User::factory()->create(['role' => 'reviewer']);

        $this->actingAs($reviewer)->get(route('notebooks.index'))->assertInertia(fn (Assert $page) => $page
            ->component('notebooks/index')
            ->where('auth.user.role', 'reviewer'));
    }

    public function test_a_member_is_told_they_are_a_member(): void
    {
        $member = User::factory()->create(['role' => 'member']);

        $this->actingAs($member)->get(route('notebooks.index'))->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.role', 'member'));
    }
}
