<?php

namespace Tests\Feature\Studio;

use App\Ai\Agents\RouterAgent;
use App\Models\Notebook;
use App\Models\Passage;
use App\Models\StudioItem;
use App\Models\UsageEvent;
use App\Models\User;
use App\Sources\EmbedSource;
use App\Studio\GenerateStudioItem;
use App\Studio\StudioGenerator;
use Axyr\Langfuse\LangfuseFacade as Langfuse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;
use Tests\TestCase;

class MindmapTest extends TestCase
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

    public function test_a_mind_map_is_a_node_tree_with_validated_references_and_quotes(): void
    {
        $id = Passage::firstOrFail()->id;
        RouterAgent::fake(fn () => json_encode(['title' => 'Orion', 'children' => [
            ['label' => 'Launch', 'refs' => ["P$id", 'P999'], 'children' => [
                ['label' => 'March 2031', 'refs' => ["P$id"], 'children' => [['label' => 'Too deep', 'refs' => [], 'children' => [['label' => 'Way too deep', 'children' => []]]]]],
            ]],
            ['label' => '   ', 'refs' => []],
            ['label' => '<b>Team</b>', 'refs' => 'nonsense'],
        ]]));

        $response = $this->actingAs($this->user)->postJson("/notebooks/{$this->notebook->id}/studio", ['kind' => 'mindmap'])->assertStatus(202);

        $item = StudioItem::findOrFail($response->json('id'));
        $this->assertSame('done', $item->status, (string) $item->error);
        $this->assertSame('Orion', $item->title);
        $tree = $item->tree;
        $this->assertSame('Orion', $tree['title']);
        $this->assertSame(['Launch', 'Team'], array_column($tree['children'], 'label'), 'blank label dropped, tags stripped');
        $this->assertSame([$id], $tree['children'][0]['refs'], 'the invented P999 is dropped');
        $this->assertSame([], $tree['children'][1]['refs']);
        $this->assertSame('March 2031', $tree['children'][0]['children'][0]['label']);
        $this->assertSame('Too deep', $tree['children'][0]['children'][0]['children'][0]['label']);
        $this->assertSame([], $tree['children'][0]['children'][0]['children'][0]['children'], 'depth is capped at three levels');

        $this->assertCount(1, $item->citations);
        $this->assertSame($id, $item->citations[0]['id']);
        $this->assertStringContainsString('Orion Project launched', $item->citations[0]['quote']);
        $this->assertTrue(UsageEvent::where('prompt_name', 'studio.mindmap')->exists());

        $this->actingAs($this->user)->getJson("/notebooks/{$this->notebook->id}/studio/{$item->id}")->assertOk()
            ->assertJsonPath('kind', 'mindmap')->assertJsonPath('tree.children.0.label', 'Launch')->assertJsonPath('citations.0.id', $id);
    }

    public function test_the_node_count_is_capped(): void
    {
        $nodes = array_map(fn ($i) => ['label' => "Node $i", 'refs' => [], 'children' => []], range(1, 100));
        $tree = app(StudioGenerator::class)->parseMindmap(json_encode(['title' => 'T', 'children' => $nodes]), [], 40, 3);

        $this->assertCount(40, $tree['children']);
    }

    public function test_an_unusable_answer_fails_the_item_with_a_plain_message(): void
    {
        RouterAgent::fake(fn () => 'I cannot draw maps.');

        $item = StudioItem::findOrFail($this->actingAs($this->user)->postJson("/notebooks/{$this->notebook->id}/studio", ['kind' => 'mindmap'])->json('id'));

        $this->assertSame('failed', $item->status);
        $this->assertSame('The model did not return a usable mind map.', $item->error);
        $this->assertNull($item->tree);
    }

    public function test_it_is_a_default_queue_job(): void
    {
        Queue::fake();
        $this->actingAs($this->user)->postJson("/notebooks/{$this->notebook->id}/studio", ['kind' => 'mindmap'])->assertStatus(202);

        Queue::assertPushedOn('default', GenerateStudioItem::class);
    }
}
