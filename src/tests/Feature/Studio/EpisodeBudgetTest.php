<?php

namespace Tests\Feature\Studio;

use App\Ai\Agents\RouterAgent;
use App\Ai\Ledger;
use App\Models\Notebook;
use App\Models\StudioItem;
use App\Models\UsageEvent;
use App\Models\User;
use App\Sources\EmbedSource;
use App\Studio\GenerateStudioItem;
use Axyr\Langfuse\LangfuseFacade as Langfuse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Audio;
use Laravel\Ai\Embeddings;
use Tests\TestCase;

/** E-1: reviewers and the owner have their own $5 cap, and an audio episode reserves its whole cost before it starts. */
class EpisodeBudgetTest extends TestCase
{
    use RefreshDatabase;

    /** A script of 5,000 characters in all: the most an episode may be. */
    private function longScript(): string
    {
        return json_encode(['title' => 'Episode', 'turns' => array_map(
            fn ($i) => ['speaker' => $i % 2 ? 'A' : 'B', 'text' => str_repeat('x', 500)], range(1, 10),
        )]);
    }

    private function episode(User $user, ?string $script = null, string $length = 'default'): StudioItem
    {
        Storage::fake('local');
        Langfuse::fake();
        Embeddings::fake();
        RouterAgent::fake(fn () => 'A summary.');
        $notebook = Notebook::create(['user_id' => $user->id, 'title' => 'T']);
        $source = $notebook->sources()->create(['title' => 'S', 'status' => 'pending', 'content' => 'A fact worth hearing about.']);
        app()->call([new EmbedSource($source->id), 'handle']);
        RouterAgent::fake(fn () => $script ?? $this->longScript());
        $item = StudioItem::create(['notebook_id' => $notebook->id, 'user_id' => $user->id, 'kind' => 'audio', 'title' => 'A', 'status' => 'pending', 'settings' => ['options' => ['length' => $length]]]);
        GenerateStudioItem::dispatchSync($item->id, 'media');

        return $item->fresh();
    }

    public function test_reviewers_and_the_owner_have_their_own_caps_members_do_not(): void
    {
        $ledger = app(Ledger::class);

        $this->assertSame(25_000_000, $ledger->capFor(User::factory()->create(['role' => 'reviewer'])), 'D-047: $25');
        $this->assertSame(50_000_000, $ledger->capFor(User::factory()->create(['role' => 'owner'])), 'D-047: $50');
        $this->assertSame(20_000_000, $ledger->capFor(User::factory()->create(['role' => 'member', 'tier' => 'pro', 'pro_until' => now()->addDay()])), 'D-047: $20');
        $this->assertSame(2_000_000, $ledger->capFor(User::factory()->create(['role' => 'member', 'tier' => 'free'])), 'D-047: $2');
        $this->assertSame(2_000_000, $ledger->capFor(User::factory()->create(['role' => 'member', 'tier' => 'pro', 'pro_until' => now()->subDay()])), 'a lapsed pro is free');

        config(['ledger.role_caps.owner' => 12_000_000]);
        $this->assertSame(12_000_000, $ledger->capFor(User::factory()->create(['role' => 'owner'])), 'a role cap wins over the pro tier cap, lower than it');

        config(['ledger.role_caps.owner' => 80_000_000]);
        $this->assertSame(80_000_000, $ledger->capFor(User::factory()->create(['role' => 'owner'])), 'and higher than it');
    }

    public function test_a_reviewer_can_make_two_full_length_episodes_with_elevenlabs(): void
    {
        config(['elevenlabs.key' => 'xi-test']);
        Http::fake(['api.elevenlabs.io/*' => Http::response('MP3', 200)]);
        $user = User::factory()->create(['role' => 'reviewer']);

        $first = $this->episode($user, null, 'long');
        $second = $this->episode($user, null, 'long');

        $this->assertSame('done', $first->status, (string) $first->error);
        $this->assertSame('done', $second->status, (string) $second->error);
        // $0.08 per 1,000 characters, 5,000 characters each: $0.40 an episode.
        $this->assertSame(2 * 5_000 * 80, (int) UsageEvent::where('provider', 'elevenlabs')->sum('cost_micro_usd'));
        $this->assertSame(0, DB::table('ledger_reservations')->count());
    }

    public function test_an_episode_that_cannot_be_afforded_is_refused_before_anything_is_spent(): void
    {
        config(['elevenlabs.key' => 'xi-test', 'ledger.role_caps.reviewer' => 150_000, 'ledger.user_caps.free' => 150_000, 'ledger.user_caps.pro' => 150_000]); // an episode needs about 400,000
        Http::fake();
        $item = $this->episode(User::factory()->create(['role' => 'reviewer']));

        $this->assertSame('failed', $item->status);
        $this->assertStringContainsString('usage limit', (string) $item->error);
        $this->assertSame(0, UsageEvent::where('prompt_name', 'studio.audio.script')->count(), 'the script was not even paid for');
        $this->assertSame(0, UsageEvent::where('kind', 'tts')->count());
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('ledger_reservations')->count());
    }

    public function test_the_calls_of_an_episode_draw_on_its_reservation_instead_of_counting_twice(): void
    {
        // The episode needs about 415,000 in all. With a cap of 700,000 it fits once; if every call also reserved on top of
        // the episode's own reservation it would need about 830,000 and fail.
        config(['elevenlabs.key' => 'xi-test', 'ledger.role_caps.reviewer' => 700_000, 'ledger.user_caps.free' => 700_000]);
        Http::fake(['api.elevenlabs.io/*' => Http::response('MP3', 200)]);

        $item = $this->episode(User::factory()->create(['role' => 'reviewer']));

        $this->assertSame('done', $item->status, (string) $item->error);
        $this->assertSame(0, DB::table('ledger_reservations')->count(), 'the episode reservation is gone when it ends');
    }

    public function test_speech_that_fails_midway_keeps_what_was_spent_and_fails_the_item_plainly(): void
    {
        Audio::fake(['dGVzdA==', fn () => throw new \RuntimeException('tts exploded sk-secret')]);
        $item = $this->episode(User::factory()->create(['role' => 'member', 'tier' => 'free']), json_encode([
            'title' => 't', 'turns' => [['speaker' => 'A', 'text' => 'One.'], ['speaker' => 'B', 'text' => 'Two.'], ['speaker' => 'A', 'text' => 'Three.']],
        ]));

        $this->assertSame('failed', $item->status);
        $this->assertStringNotContainsString('sk-secret', (string) $item->error);
        $this->assertNotEmpty($item->error);
        $this->assertSame(1, UsageEvent::where('prompt_name', 'studio.audio.script')->count(), 'the script was spent');
        $this->assertSame(1, UsageEvent::where('kind', 'tts')->count(), 'the first turn was spent, the failed one was not');
        $this->assertSame(0, DB::table('ledger_reservations')->count(), 'no hold is left, the episode reservation included');
    }
}
