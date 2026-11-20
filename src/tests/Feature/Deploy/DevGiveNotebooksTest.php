<?php

namespace Tests\Feature\Deploy;

use App\Models\Note;
use App\Models\Notebook;
use App\Models\Source;
use App\Models\StudioItem;
use App\Models\UsageEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DevGiveNotebooksTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_moves_the_demo_notebooks_with_what_hangs_off_them_and_leaves_the_ledger_and_other_notebooks(): void
    {
        $from = User::factory()->create(['email' => 'free@dev.test']);
        $to = User::factory()->create(['email' => 'owner@example.test']);
        $demo = Notebook::create(['user_id' => $from->id, 'title' => 'Demo · One', 'emoji' => '📓']);
        $mine = Notebook::create(['user_id' => $from->id, 'title' => 'Something else', 'emoji' => '📓']);
        $source = $demo->sources()->create(['kind' => 'text', 'title' => 's', 'content' => 'x', 'status' => 'ready']);
        $demo->notes()->create(['title' => 'n', 'content' => 'c']);
        $item = StudioItem::create(['notebook_id' => $demo->id, 'user_id' => $from->id, 'kind' => 'faq', 'title' => 'FAQ', 'status' => 'done']);
        $event = UsageEvent::create(['user_id' => $from->id, 'notebook_id' => $demo->id, 'provider' => 'mistral', 'model' => 'm', 'kind' => 'chat', 'units' => [], 'cost_micro_usd' => 5]);

        $this->artisan('dev:give-notebooks', ['from' => 'free@dev.test', 'to' => 'owner@example.test'])->assertSuccessful();

        $this->assertSame($to->id, $demo->fresh()->user_id);
        $this->assertSame($to->id, $item->fresh()->user_id);
        $this->assertSame($from->id, $mine->fresh()->user_id);
        $this->assertSame($from->id, $event->fresh()->user_id, 'the ledger stays with who spent');
        $this->assertSame(1, Source::where('notebook_id', $demo->id)->count());
        $this->assertSame(1, Note::where('notebook_id', $demo->id)->count());

        // Run again: nothing left to move.
        $this->artisan('dev:give-notebooks', ['from' => 'free@dev.test', 'to' => 'owner@example.test'])->expectsOutputToContain('0 notebook(s) moved')->assertSuccessful();
    }

    public function test_it_refuses_in_production(): void
    {
        $this->app['env'] = 'production';
        User::factory()->create(['email' => 'a@dev.test']);
        User::factory()->create(['email' => 'b@dev.test']);

        $this->artisan('dev:give-notebooks', ['from' => 'a@dev.test', 'to' => 'b@dev.test'])->assertFailed();
    }
}
