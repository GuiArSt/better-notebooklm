<?php

namespace Tests\Feature\Studio;

use App\Ai\Agents\RouterAgent;
use App\Ai\FriendlyError;
use App\Models\Notebook;
use App\Models\Passage;
use App\Models\StudioItem;
use App\Models\UsageEvent;
use App\Models\User;
use App\Sources\EmbedSource;
use App\Studio\AudioJoiner;
use App\Studio\GenerateStudioItem;
use App\Studio\StudioGenerator;
use Axyr\Langfuse\LangfuseFacade as Langfuse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Audio;
use Laravel\Ai\Embeddings;
use Tests\TestCase;

class StudioTest extends TestCase
{
    use RefreshDatabase;

    private const TEXT = "The Orion Project launched in March 2031 with a budget of 4.2 million euros.\n\nIts lead engineer, Ada Okafor, moved the team to Lisbon in 2033.";

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
        $source = $this->notebook->sources()->create(['title' => 'Orion notes', 'content' => self::TEXT, 'status' => 'pending']);
        app()->call([new EmbedSource($source->id), 'handle']);
    }

    private function start(string $kind)
    {
        return $this->actingAs($this->user)->postJson("/notebooks/{$this->notebook->id}/studio", ['kind' => $kind]);
    }

    public function test_a_briefing_is_written_from_the_sources_with_validated_citations(): void
    {
        $id = Passage::firstOrFail()->id;
        RouterAgent::fake(fn () => "## Summary\nOrion launched in March 2031 [P$id]. Aliens funded it [P999].");

        $response = $this->start('briefing')->assertStatus(202);

        $item = StudioItem::findOrFail($response->json('id'));
        $this->assertSame('done', $item->status, (string) $item->error);
        $this->assertStringContainsString('March 2031', $item->content);
        $this->assertCount(1, $item->citations);
        $this->assertSame($id, $item->citations[0]['id']);
        $this->assertStringContainsString('Orion Project launched', $item->citations[0]['quote']);
        $this->assertSame('citation_dropped', Langfuse::getFacadeRoot()->scores()[0]->name);
        $this->assertTrue(UsageEvent::where('prompt_name', 'studio.report.briefing')->where('cost_micro_usd', '>', 0)->exists());

        $this->actingAs($this->user)->getJson("/notebooks/{$this->notebook->id}/studio/{$item->id}")->assertOk()
            ->assertJsonPath('status', 'done')->assertJsonPath('citations.0.id', $id);
        $this->actingAs($this->user)->getJson("/notebooks/{$this->notebook->id}/studio")->assertOk()->assertJsonCount(1, 'items');
    }

    public function test_each_report_kind_uses_its_own_prompt(): void
    {
        RouterAgent::fake(fn () => 'Text.');

        foreach (['study_guide', 'faq'] as $kind) {
            $this->start($kind)->assertStatus(202);
            $this->assertTrue(UsageEvent::where('prompt_name', "studio.report.$kind")->exists(), $kind);
        }
    }

    public function test_an_audio_overview_is_a_script_then_speech_per_turn_then_one_mp3(): void
    {
        Audio::fake();
        $script = json_encode(['title' => 'Orion in five minutes', 'turns' => [
            ['speaker' => 'A', 'text' => 'So what is the Orion Project?'],
            ['speaker' => 'B', 'text' => 'It launched in March 2031.'],
            ['speaker' => 'A', 'text' => 'And who leads it?'],
            ['speaker' => 'B', 'text' => 'Ada Okafor, who moved the team to Lisbon.'],
        ]]);
        RouterAgent::fake(fn () => "```json\n$script\n```");

        $response = $this->start('audio')->assertStatus(202);

        $item = StudioItem::findOrFail($response->json('id'));
        $this->assertSame('done', $item->status, (string) $item->error);
        $this->assertSame('Orion in five minutes', $item->title);
        $this->assertCount(4, $item->script);

        // One script call and one speech call per turn, each in the ledger; speech is priced per character.
        $this->assertSame(1, UsageEvent::where('prompt_name', 'studio.audio.script')->count());
        $speech = UsageEvent::where('kind', 'tts')->get();
        $this->assertCount(4, $speech);
        $characters = collect($item->script)->sum(fn ($t) => mb_strlen($t['text']));
        $this->assertSame($characters, $speech->sum(fn ($e) => $e->units['characters']));
        $this->assertSame($characters * 13, (int) $speech->sum('cost_micro_usd'), 'Voxtral TTS: 13 micro-USD per character');
        $this->assertGreaterThan(0, $speech->sum('cost_micro_usd'));
        Audio::assertGenerated(fn ($prompt) => $prompt->text === 'So what is the Orion Project?');

        // A and B get different voices.
        Audio::assertGenerated(fn ($prompt) => $prompt->text === 'It launched in March 2031.' && $prompt->voice === config('models.voices.b'));
        Audio::assertGenerated(fn ($prompt) => $prompt->text === 'And who leads it?' && $prompt->voice === config('models.voices.a'));

        // One file, served as audio.
        Storage::disk('local')->assertExists($item->file_path);
        $this->actingAs($this->user)->get("/notebooks/{$this->notebook->id}/studio/{$item->id}/audio")->assertOk()->assertHeader('Content-Type', 'audio/mpeg');
        $this->assertNotNull($item->fresh()->toArrayForClient()['audio_url']);
        $this->assertSame(0, DB::table('ledger_reservations')->count());
    }

    public function test_a_speech_failure_marks_the_item_failed_and_leaves_no_hold(): void
    {
        Audio::fake(['dGVzdA==', fn () => throw new \RuntimeException('tts down')]);
        RouterAgent::fake(fn () => json_encode(['title' => 't', 'turns' => [['speaker' => 'A', 'text' => 'One.'], ['speaker' => 'B', 'text' => 'Two.']]]));

        $item = StudioItem::findOrFail($this->start('audio')->assertStatus(202)->json('id'));

        $this->assertSame('failed', $item->status);
        $this->assertStringNotContainsString('tts down', $item->error, 'raw exception text is not shown');
        $this->assertSame(FriendlyError::GENERIC, $item->error);
        $this->assertNull($item->file_path);
        $this->assertSame(0, DB::table('ledger_reservations')->count());
    }

    public function test_a_cap_stops_the_item_honestly_before_any_model_call(): void
    {
        config(['ledger.global_cap' => 1]);

        $item = StudioItem::findOrFail($this->start('briefing')->assertStatus(202)->json('id'));

        $this->assertSame('failed', $item->status);
        $this->assertStringContainsString('spending limit', $item->error);
        $this->assertSame(0, UsageEvent::where('prompt_name', 'studio.report.briefing')->count());
    }

    public function test_the_rules_of_the_endpoint(): void
    {
        Queue::fake();
        $this->start('video')->assertStatus(422);

        $this->start('briefing')->assertStatus(202);
        $this->start('faq')->assertStatus(202);
        $this->start('study_guide')->assertStatus(429);

        $empty = Notebook::create(['user_id' => $this->user->id, 'title' => 'E']);
        $this->actingAs($this->user)->postJson("/notebooks/{$empty->id}/studio", ['kind' => 'faq'])->assertStatus(422);

        $item = StudioItem::first();
        $other = User::factory()->create();
        $this->actingAs($other)->getJson("/notebooks/{$this->notebook->id}/studio")->assertNotFound();
        $this->actingAs($other)->getJson("/notebooks/{$this->notebook->id}/studio/{$item->id}")->assertNotFound();
        $this->actingAs($this->user)->deleteJson("/notebooks/{$this->notebook->id}/studio/{$item->id}")->assertOk();
        $this->assertSame(1, StudioItem::count());
    }

    public function test_audio_goes_to_the_media_queue_and_reports_to_the_default_queue(): void
    {
        Queue::fake();
        $this->start('audio');
        $this->start('briefing');

        Queue::assertPushedOn('media', GenerateStudioItem::class);
        Queue::assertPushedOn('default', GenerateStudioItem::class);
    }

    /** HC-2 (Hipparchus): a turn that does not fit ends the script; later short turns are not kept. */
    public function test_a_turn_that_does_not_fit_ends_the_script_instead_of_being_skipped(): void
    {
        $gen = app(StudioGenerator::class);
        $json = json_encode(['title' => 'T', 'turns' => [
            ['speaker' => 'A', 'text' => 'First question?'],
            ['speaker' => 'B', 'text' => 'First answer.'],
            ['speaker' => 'A', 'text' => str_repeat('long ', 50)],
            ['speaker' => 'B', 'text' => 'A short reply to a question nobody asked.'],
        ]]);

        $parsed = $gen->parseScript($json, 24, 100);

        $this->assertSame(['First question?', 'First answer.'], array_column($parsed['turns'], 'text'));

        // The same with only one turn that fits: too short, not a script of orphan replies.
        $this->expectExceptionMessage('too short');
        $gen->parseScript(json_encode(['title' => 'T', 'turns' => [['speaker' => 'A', 'text' => str_repeat('long ', 50)], ['speaker' => 'B', 'text' => 'Orphan.'], ['speaker' => 'A', 'text' => 'Orphan two.']]]), 24, 100);
    }

    public function test_the_script_parser_enforces_turn_and_character_limits(): void
    {
        $turns = array_map(fn ($i) => ['speaker' => $i % 2 ? 'B' : 'A', 'text' => str_repeat('x', 100)], range(1, 30));
        $parsed = app(StudioGenerator::class)->parseScript(json_encode(['title' => 'T', 'turns' => $turns]), 24, 1000);

        $this->assertCount(10, $parsed['turns']);

        $this->expectException(\RuntimeException::class);
        app(StudioGenerator::class)->parseScript('not json', 24, 1000);
    }

    public function test_the_joiner_falls_back_to_appending_when_ffmpeg_fails(): void
    {
        Process::fake(['*' => Process::result('', 'boom', 1)]);

        $this->assertStringEndsWith('onetwo', app(AudioJoiner::class)->join(['one', 'two']), 'the frames are appended (behind our own ID3 tag)');
    }

    public function test_the_joiner_really_joins_two_mp3_files_with_ffmpeg(): void
    {
        if (trim((string) shell_exec('command -v ffmpeg')) === '') {
            $this->markTestSkipped('ffmpeg is not installed here');
        }
        $parts = [];
        foreach ([1, 2] as $i) {
            $file = sys_get_temp_dir()."/tone$i.mp3";
            shell_exec('ffmpeg -y -loglevel error -f lavfi -i sine=frequency='.(300 * $i)." -t 1 -q:a 9 $file");
            $parts[] = (string) file_get_contents($file);
            @unlink($file);
        }

        $joined = app(AudioJoiner::class)->join($parts);
        $out = sys_get_temp_dir().'/joined.mp3';
        file_put_contents($out, $joined);
        $seconds = (float) trim((string) shell_exec("ffprobe -v error -show_entries format=duration -of default=nw=1:nk=1 $out"));
        @unlink($out);

        $this->assertEqualsWithDelta(2.0, $seconds, 0.3);
    }
}
