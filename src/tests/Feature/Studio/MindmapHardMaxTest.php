<?php

namespace Tests\Feature\Studio;

use App\Ai\Agents\RouterAgent;
use App\Models\Notebook;
use App\Models\StudioItem;
use App\Models\UsageEvent;
use App\Models\User;
use App\Sources\EmbedSource;
use App\Studio\StudioGenerator;
use Axyr\Langfuse\LangfuseFacade as Langfuse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;
use Tests\TestCase;

/** D-054: a mind map label or title is never cut. Up to the hard maximum it is kept; beyond it, one repair call, then a clean failure. */
class MindmapHardMaxTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Notebook $notebook;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Langfuse::fake();
        Embeddings::fake();
        RouterAgent::fake(fn () => 'A summary.');
        $this->user = User::factory()->create();
        $this->notebook = Notebook::create(['user_id' => $this->user->id, 'title' => 'T']);
        $source = $this->notebook->sources()->create(['title' => 'Orion notes', 'status' => 'pending', 'content' => 'The Orion Project launched in March 2031.']);
        app()->call([new EmbedSource($source->id), 'handle']);
    }

    private function map(string $label = 'Launch', string $title = 'Orion', string $child = 'Date'): string
    {
        return json_encode(['title' => $title, 'children' => [
            ['label' => $label, 'refs' => [], 'children' => [['label' => $child, 'refs' => [], 'children' => []]]],
            ['label' => 'Team', 'refs' => [], 'children' => []],
            ['label' => 'Goal', 'refs' => [], 'children' => []],
        ]]);
    }

    private function make(): StudioItem
    {
        return StudioItem::findOrFail($this->actingAs($this->user)->postJson("/notebooks/{$this->notebook->id}/studio", ['kind' => 'mindmap'])->assertStatus(202)->json('id'));
    }

    public function test_labels_and_title_at_their_hard_maximum_are_kept_uncut_with_no_repair(): void
    {
        $long = str_repeat('m', 199).'.';
        RouterAgent::fake(fn () => $this->map($long, $long, $long));

        $item = $this->make();

        $this->assertSame('done', $item->status, (string) $item->error);
        $this->assertSame($long, $item->title);
        $this->assertSame($long, $item->tree['children'][0]['label']);
        $this->assertSame($long, $item->tree['children'][0]['children'][0]['label']);
        $this->assertSame(0, UsageEvent::where('prompt_name', 'studio.mindmap.repair')->count());
    }

    public function test_one_over_gets_one_repair_and_the_repaired_label_is_kept(): void
    {
        $calls = 0;
        RouterAgent::fake(function () use (&$calls) {
            $calls++;

            return $calls === 1 ? $this->map(str_repeat('x', 201)) : $this->map('Launch, shortened');
        });

        $item = $this->make();

        $this->assertSame('done', $item->status, (string) $item->error);
        $this->assertSame(2, $calls);
        $this->assertSame(1, UsageEvent::where('prompt_name', 'studio.mindmap.repair')->count());
        $this->assertSame('Launch, shortened', $item->tree['children'][0]['label']);
    }

    public function test_still_over_after_the_repair_fails_cleanly(): void
    {
        RouterAgent::fake(fn () => $this->map('Launch', str_repeat('t', 201)));

        $item = $this->make();

        $this->assertSame('failed', $item->status);
        $this->assertSame('The model did not return a usable mind map.', $item->error);
        $this->assertNull($item->tree);
        $this->assertSame(1, UsageEvent::where('prompt_name', 'studio.mindmap.repair')->count());
    }

    public function test_the_checker_names_the_node_by_its_position(): void
    {
        $data = json_decode($this->map('Launch', 'Orion', str_repeat('c', 250)), true);

        $this->assertSame(['The label of node 1.1 is 250 characters; the limit is 200. Shorten it.'], app(StudioGenerator::class)->mindmapLengthProblems($data, 40, 3));
    }
}
