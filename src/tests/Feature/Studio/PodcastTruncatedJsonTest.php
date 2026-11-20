<?php

namespace Tests\Feature\Studio;

use App\Ai\Agents\RouterAgent;
use App\Models\Notebook;
use App\Models\StudioItem;
use App\Models\UsageEvent;
use App\Models\User;
use App\Sources\EmbedSource;
use App\Studio\GenerateStudioItem;
use Axyr\Langfuse\LangfuseFacade as Langfuse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Audio;
use Laravel\Ai\Embeddings;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** A structured answer cut by the output cap (Sonnet 5.5 thinks first, and the thinking counts) is never taken for a result: the item fails with its message and what was spent is in the ledger. */
class PodcastTruncatedJsonTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string}> kind => the answer cut mid-string, and the prompt that was paid for */
    public static function kinds(): array
    {
        return [
            'audio' => ['audio', 'studio.audio.script', '{"title":"T","turns":[{"speaker":"A","text":"What happened to the bud'],
            'quiz' => ['quiz', 'studio.quiz', '{"questions":[{"question":"What is the budget?","options":["a","b'],
            'mindmap' => ['mindmap', 'studio.mindmap', '{"title":"Orion","children":[{"title":"Bud'],
            'slides' => ['slides', 'studio.slides', '{"title":"Orion","slides":[{"template":"title","title":"Or'],
        ];
    }

    /** @dataProvider kinds */
    #[DataProvider('kinds')]
    public function test_a_truncated_answer_fails_the_item_and_is_in_the_ledger(string $kind, string $prompt, string $cut): void
    {
        Storage::fake('local');
        Langfuse::fake();
        Embeddings::fake();
        Audio::fake();
        RouterAgent::fake(fn () => 'A summary.');
        $user = User::factory()->create(['role' => 'member', 'tier' => 'pro', 'pro_until' => now()->addDay()]);
        $notebook = Notebook::create(['user_id' => $user->id, 'title' => 'T']);
        $source = $notebook->sources()->create(['title' => 'S', 'content' => 'The Orion Project launched in March 2031 with a budget of four million euros.', 'status' => 'pending']);
        app()->call([new EmbedSource($source->id), 'handle']);
        RouterAgent::fake(fn () => $cut);
        $item = StudioItem::create(['notebook_id' => $notebook->id, 'user_id' => $user->id, 'kind' => $kind, 'title' => 'X', 'status' => 'pending', 'settings' => ['options' => []]]);

        GenerateStudioItem::dispatchSync($item->id, 'media');

        $item->refresh();
        $this->assertSame('failed', $item->status);
        $this->assertNotEmpty($item->error, 'the failure reaches the user');
        $this->assertNull($item->file_path);
        $this->assertGreaterThanOrEqual(1, UsageEvent::where('studio_item_id', $item->id)->where('prompt_name', $prompt)->count(), 'the call that was cut was still paid for, and is in the ledger');
        $this->assertSame(0, DB::table('ledger_reservations')->count());
    }

    public function test_the_structured_caps_leave_room_for_thinking(): void
    {
        foreach (['audio_script' => 20_000, 'mindmap' => 16_000, 'slides' => 16_000, 'quiz' => 16_000, 'report' => 16_000] as $key => $floor) {
            $this->assertGreaterThanOrEqual($floor, config("studio.{$key}_max_output_tokens"), "$key keeps room for the model's thinking");
        }
    }
}
