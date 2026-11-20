<?php

namespace Tests\Feature;

use App\Ai\Agents\RouterAgent;
use App\Ai\Router;
use App\Models\Notebook;
use App\Models\UsageEvent;
use App\Models\User;
use App\Sources\EmbedSource;
use Axyr\Langfuse\LangfuseFacade as Langfuse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;
use Tests\TestCase;

/** Argus: the 1,024-token chat cap cut a real pro answer (Claude's hidden thinking took most of it). The limit and the hold are one number, now higher. */
class ChatLengthTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_provider_has_its_own_output_limit_claude_roomy_and_mistral_unchanged(): void
    {
        $this->assertSame(8000, Router::maxOutputFor('anthropic'), 'Claude: room for hidden thinking and a long answer');
        $this->assertSame(1024, Router::maxOutputFor('mistral'), 'Mistral Small is unchanged');
        $this->assertSame(1024, Router::maxOutputFor('any-other-provider'), 'the default');
    }

    public function test_the_limit_sent_and_the_cost_held_follow_the_users_provider(): void
    {
        Storage::fake('local');
        Langfuse::fake();
        RouterAgent::fake(fn () => 'ok');
        $free = User::factory()->create(['tier' => 'free']);
        $pro = User::factory()->create(['tier' => 'pro', 'pro_until' => now()->addDay()]);

        app(Router::class)->text($free, 'helper.title', ['text' => 'a'], 'hi');
        app(Router::class)->text($pro, 'helper.title', ['text' => 'a'], 'hi');

        RouterAgent::assertPrompted(fn ($p) => $p->agent->maxTokens() === 1024);
        RouterAgent::assertPrompted(fn ($p) => $p->agent->maxTokens() === 8000);
        $events = UsageEvent::where('prompt_name', 'helper.title')->orderBy('id')->get();
        $this->assertSame(['mistral', 'anthropic'], $events->pluck('provider')->all());
    }

    public function test_a_long_answer_is_delivered_whole_and_billed_for_what_it_used_not_cut_at_the_old_limit(): void
    {
        Storage::fake('local');
        Langfuse::fake();
        Embeddings::fake();
        RouterAgent::fake(fn () => 'A summary.');
        $user = User::factory()->create(['tier' => 'pro', 'pro_until' => now()->addDay()]);
        $notebook = Notebook::create(['user_id' => $user->id, 'title' => 'T']);
        $source = $notebook->sources()->create(['title' => 'S', 'status' => 'pending', 'content' => 'The Orion Project launched in March 2031.']);
        app()->call([new EmbedSource($source->id), 'handle']);

        // About 1,500 tokens by the app's own count (two characters a token): more than the old 1,024.
        $long = str_repeat('A long and careful sentence about the project. ', 65).'THE END';
        RouterAgent::fake(fn () => $long);

        $body = $this->actingAs($user)->post("/notebooks/{$notebook->id}/chat", ['question' => 'Explain everything in detail.'])->streamedContent();

        $text = collect(explode("\n\n", trim($body)))->map(fn ($chunk) => json_decode(substr($chunk, 6), true))->where('type', 'text')->pluck('delta')->implode('');
        $this->assertSame($long, $text, 'the whole answer reached the browser');
        $event = UsageEvent::where('prompt_name', 'chat.answer')->firstOrFail();
        $this->assertGreaterThan(1024, $event->units['output_tokens'], 'billed for the real length, not clamped to the old limit');
        $this->assertSame('anthropic', $event->provider);
        $this->assertSame(0, \DB::table('ledger_reservations')->count());
    }
}
