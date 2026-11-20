<?php

namespace Tests\Feature\Interface;

use App\Models\Notebook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_page_that_is_not_there_answers_in_the_apps_own_design_with_a_way_back(): void
    {
        $this->get('/no-such-page')
            ->assertNotFound()
            ->assertSee('data-testid="error-page"', false)
            ->assertSee('This page is not here.')
            ->assertSee('Back to my notebooks')
            ->assertDontSee('Illuminate');
    }

    public function test_someone_elses_notebook_is_the_same_page_not_a_stock_one(): void
    {
        $owner = User::factory()->create();
        $notebook = Notebook::create(['user_id' => $owner->id, 'title' => 'Private']);

        $this->actingAs(User::factory()->create())->get(route('notebooks.show', $notebook))
            ->assertNotFound()
            ->assertSee('data-testid="error-page"', false);
    }

    public function test_every_error_the_user_can_meet_has_a_page_with_the_way_back(): void
    {
        foreach ([403, 404, 419, 500, 503] as $status) {
            $view = view("errors.$status")->render();

            $this->assertStringContainsString((string) $status, $view);
            $this->assertStringContainsString('Back to my notebooks', $view, "$status has no way back");
        }
    }
}
