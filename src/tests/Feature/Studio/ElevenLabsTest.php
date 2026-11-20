<?php

namespace Tests\Feature\Studio;

use App\Ai\Agents\RouterAgent;
use App\Ai\ElevenLabs;
use App\Ai\Router;
use App\Models\Notebook;
use App\Models\StudioItem;
use App\Models\UsageEvent;
use App\Models\User;
use App\Sources\EmbedSource;
use App\Studio\GenerateStudioItem;
use App\Studio\StudioGenerator;
use Axyr\Langfuse\LangfuseFacade as Langfuse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Audio;
use Laravel\Ai\Embeddings;
use Tests\TestCase;

/** D-014: ElevenLabs Text to Dialogue voices the podcast for reviewers, the owner and pro users; everyone else keeps Mistral. */
class ElevenLabsTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'xi-test-key-not-a-real-one';

    private function script(int $turns = 4, int $length = 40): string
    {
        return json_encode(['title' => 'Episode', 'turns' => array_map(
            fn ($i) => ['speaker' => $i % 2 ? 'A' : 'B', 'text' => str_pad("Turn $i. ", $length, 'x')], range(1, $turns),
        )]);
    }

    /** A notebook with one ready source for $user. */
    private function notebookFor(User $user): Notebook
    {
        Storage::fake('local');
        Langfuse::fake();
        Embeddings::fake();
        RouterAgent::fake(fn () => 'A summary.');
        $notebook = Notebook::create(['user_id' => $user->id, 'title' => 'T']);
        $source = $notebook->sources()->create(['title' => 'S', 'content' => 'A fact worth hearing about.', 'status' => 'pending']);
        app()->call([new EmbedSource($source->id), 'handle']);

        return $notebook;
    }

    private function make(User $user, string $script): StudioItem
    {
        $notebook = $this->notebookFor($user);
        RouterAgent::fake(fn () => $script);
        $item = StudioItem::create(['notebook_id' => $notebook->id, 'user_id' => $user->id, 'kind' => 'audio', 'title' => 'A', 'status' => 'pending']);
        app(StudioGenerator::class)->generate($item);

        return $item->fresh();
    }

    public function test_a_reviewer_hears_elevenlabs_through_the_router_with_the_documented_request(): void
    {
        config(['elevenlabs.key' => self::KEY, 'elevenlabs.voices.a' => 'voice-host-a', 'elevenlabs.voices.b' => 'voice-host-b']);
        Http::fake(['api.elevenlabs.io/*' => Http::response('MP3-FROM-ELEVENLABS', 200, ['Content-Type' => 'audio/mpeg'])]);
        Audio::fake();
        $user = User::factory()->create(['role' => 'reviewer']);

        $item = $this->make($user, $this->script(4));

        $this->assertSame('done', $item->status, (string) $item->error);
        Audio::assertNothingGenerated(); // Mistral's voice was not used
        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->method() === 'POST'
                && str_starts_with($request->url(), 'https://api.elevenlabs.io/v1/text-to-dialogue')
                && str_contains($request->url(), 'output_format=mp3_44100_128')
                && $request->header('xi-api-key') === [self::KEY]
                && $body['model_id'] === 'eleven_v4'
                && count($body['inputs']) === 4
                && $body['inputs'][0]['voice_id'] === 'voice-host-a'
                && $body['inputs'][1]['voice_id'] === 'voice-host-b'
                && str_contains($body['inputs'][0]['text'], 'Turn 1.');
        });
        $this->assertStringEndsWith('MP3-FROM-ELEVENLABS', Storage::disk('local')->get($item->file_path), 'the audio ElevenLabs returned (behind the synthetic-audio tag)');

        // The ledger: provider elevenlabs, priced at $0.08 per 1,000 characters = 80 micro-USD per character.
        $event = UsageEvent::where('provider', 'elevenlabs')->firstOrFail();
        $this->assertSame('tts', $event->kind);
        $this->assertSame(160, $event->units['characters']);
        $this->assertSame(160 * 80, $event->cost_micro_usd);
        $this->assertSame(0, DB::table('ledger_reservations')->count());
        $this->assertNotNull($event->langfuse_trace_id);
    }

    public function test_the_key_never_reaches_the_trace_or_the_ledger(): void
    {
        config(['elevenlabs.key' => self::KEY]);
        Http::fake(['api.elevenlabs.io/*' => Http::response('MP3', 200)]);
        $fake = Langfuse::fake();
        $user = User::factory()->create(['role' => 'reviewer']);

        $this->make($user, $this->script(2));
        $payload = json_encode(Langfuse::getFacadeRoot()->exported()).json_encode(UsageEvent::all());

        $this->assertStringNotContainsString(self::KEY, $payload);
    }

    public function test_pro_users_and_the_owner_hear_elevenlabs_but_free_members_keep_mistral(): void
    {
        config(['elevenlabs.key' => self::KEY]);
        $generator = app(StudioGenerator::class);

        $this->assertTrue($generator->usesElevenLabs(User::factory()->create(['tier' => 'pro'])));
        $this->assertTrue($generator->usesElevenLabs(User::factory()->create(['role' => 'owner'])));
        $this->assertTrue($generator->usesElevenLabs(User::factory()->create(['role' => 'reviewer'])));
        $this->assertFalse($generator->usesElevenLabs(User::factory()->create(['role' => 'member', 'tier' => 'free'])));

        config(['elevenlabs.key' => null]);
        $this->assertFalse($generator->usesElevenLabs(User::factory()->create(['role' => 'reviewer'])), 'no key, no ElevenLabs');
    }

    public function test_a_free_member_hears_mistral_and_elevenlabs_is_never_called(): void
    {
        config(['elevenlabs.key' => self::KEY]);
        Http::fake();
        Audio::fake();
        $user = User::factory()->create(['role' => 'member', 'tier' => 'free']);

        $item = $this->make($user, $this->script(4));

        $this->assertSame('done', $item->status, (string) $item->error);
        Http::assertNothingSent();
        $this->assertSame(0, UsageEvent::where('provider', 'elevenlabs')->count());
        $this->assertSame(4, UsageEvent::where('provider', 'mistral')->where('kind', 'tts')->count());
    }

    public function test_a_long_script_goes_in_several_requests_within_the_documented_limit(): void
    {
        config(['elevenlabs.key' => self::KEY, 'elevenlabs.max_chars_per_request' => 100]);
        Http::fake(['api.elevenlabs.io/*' => Http::response('MP3', 200)]);
        $user = User::factory()->create(['role' => 'reviewer']);

        $item = $this->make($user, $this->script(6, 40)); // 6 x 40 characters, 100 per request: 3 requests of 2 turns

        $this->assertSame('done', $item->status, (string) $item->error);
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $r) => count($r->data()['inputs']) === 2);
        $this->assertSame(3, UsageEvent::where('provider', 'elevenlabs')->count());
        $this->assertSame(240, UsageEvent::where('provider', 'elevenlabs')->get()->sum(fn ($e) => $e->units['characters']));
    }

    public function test_if_elevenlabs_fails_the_episode_is_made_with_mistral_and_nothing_is_charged_for_the_failure(): void
    {
        config(['elevenlabs.key' => self::KEY]);
        Http::fake(['api.elevenlabs.io/*' => Http::response('{"detail":"bad key sk-leaked"}', 401)]);
        Audio::fake();
        $user = User::factory()->create(['role' => 'reviewer']);

        $item = $this->make($user, $this->script(4));

        $this->assertSame('done', $item->status, (string) $item->error);
        $this->assertSame(0, UsageEvent::where('provider', 'elevenlabs')->count(), 'a failed call is not charged');
        $this->assertSame(4, UsageEvent::where('provider', 'mistral')->where('kind', 'tts')->count());
        $this->assertSame(0, DB::table('ledger_reservations')->count());

        // HC-1 (Hipparchus): the settings say what was really heard, and why.
        $this->assertSame('mistral', $item->settings['models']['speech']['provider']);
        $this->assertNotSame('', $item->settings['voice_fallback']);
        $this->assertStringContainsString('Exception', $item->settings['voice_fallback'], 'the error class is named');
    }

    public function test_a_cap_stops_an_elevenlabs_call_before_it_is_made(): void
    {
        config(['elevenlabs.key' => self::KEY, 'ledger.user_caps.free' => 1_000_000, 'ledger.user_caps.pro' => 1_000_000, 'ledger.role_caps.reviewer' => 1_000_000]);
        Http::fake();
        $user = User::factory()->create(['role' => 'reviewer', 'tier' => 'free']);
        $notebook = $this->notebookFor($user);
        // Spend nearly everything first, so the dialogue (160 characters = 12,800 micro-USD) would cross the cap.
        UsageEvent::create(['user_id' => $user->id, 'provider' => 'x', 'model' => 'x', 'kind' => 'chat', 'units' => [], 'cost_micro_usd' => 995_000, 'key_owner' => 'app']);
        RouterAgent::fake(fn () => $this->script(4));
        $item = StudioItem::create(['notebook_id' => $notebook->id, 'user_id' => $user->id, 'kind' => 'audio', 'title' => 'A', 'status' => 'pending']);

        GenerateStudioItem::dispatchSync($item->id, 'media');

        $item->refresh();
        $this->assertSame('failed', $item->status);
        $this->assertStringContainsString('usage limit', (string) $item->error);
        Http::assertNothingSent();
    }

    public function test_the_client_refuses_without_a_key_and_shows_no_response_body(): void
    {
        config(['elevenlabs.key' => null]);
        $this->expectExceptionMessage('not configured');
        app(ElevenLabs::class)->dialogue([['text' => 'x', 'voice_id' => 'v']], 'eleven_v3');
    }

    public function test_an_elevenlabs_error_names_its_code_but_never_the_key_or_the_body(): void
    {
        config(['elevenlabs.key' => self::KEY]);
        Http::fake(['api.elevenlabs.io/*' => Http::response(['detail' => ['status' => 'api_key_id_used_as_api_key', 'message' => 'secret detail '.self::KEY]], 400)]);

        try {
            app(ElevenLabs::class)->dialogue([['text' => 'x', 'voice_id' => 'v']], 'eleven_v3');
            $this->fail('expected an exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('ElevenLabs answered with status 400 (api_key_id_used_as_api_key).', $e->getMessage());
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
        }
    }

    public function test_the_credits_elevenlabs_reports_are_kept_in_the_ledger(): void
    {
        config(['elevenlabs.key' => self::KEY]);
        Http::fake(['api.elevenlabs.io/*' => Http::response('MP3', 200, ['character-cost' => '58'])]);
        Langfuse::fake();
        $user = User::factory()->create(['role' => 'reviewer']);

        app(Router::class)->dialogue($user, [['speaker' => 'A', 'text' => str_repeat('a', 100)], ['speaker' => 'B', 'text' => str_repeat('b', 166)]]);

        $event = UsageEvent::where('provider', 'elevenlabs')->firstOrFail();
        $this->assertSame(266, $event->units['characters']);
        $this->assertSame(58, $event->units['credits'], "ElevenLabs' own count of what the call used");
        $this->assertSame(266 * 80, $event->cost_micro_usd, 'the dollar cost is still our per-character price (unverified, see config/pricing.php)');
    }
}
