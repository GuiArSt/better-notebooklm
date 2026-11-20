<?php

namespace Tests\Feature\Ai;

use App\Ai\Agents\RouterAgent;
use App\Ai\Exceptions\UserFacingException;
use App\Ai\Router;
use App\Models\Notebook;
use App\Models\StudioItem;
use App\Models\User;
use App\Sources\EmbedSource;
use App\Studio\StudioGenerator;
use Axyr\Langfuse\LangfuseFacade as Langfuse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;
use Tests\TestCase;

/** The owner's rule: no silent states. A call that stopped at its output limit, or said nothing, is never stored as a finished answer. */
class NoSilentStatesTest extends TestCase
{
    use RefreshDatabase;

    private const EN = 'The Orion Project launched in March 2031 with a budget of four million euros. Its lead engineer moved the team to Lisbon in 2033, and the project is now one of the largest of its kind in the country.';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Langfuse::fake();
        Embeddings::fake();
        config(['ai.providers.anthropic.key' => 'test-key-not-real']);
        Http::fake(fn () => $this->answer(...$this->reply));
    }

    private function pro(): User
    {
        return User::factory()->create(['tier' => 'pro', 'pro_until' => now()->addDay()]);
    }

    /** @var array{0: string, 1: string, 2: bool} text, stop reason, streamed */
    private array $reply = ['', 'end_turn', false];

    private function claude(string $text, string $stop): void
    {
        $this->reply = [$text, $stop, false];
    }

    private function claudeStream(string $text, string $stop): void
    {
        $this->reply = [$text, $stop, true];
    }

    private function answer(string $text, string $stop, bool $streamed)
    {
        if (! $streamed) {
            return Http::response(['id' => 'm', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5', 'stop_reason' => $stop, 'content' => [['type' => 'text', 'text' => $text]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 20]], 200);
        }

        return Http::response(
            "event: message_start\ndata: {\"type\":\"message_start\",\"message\":{\"id\":\"m\",\"usage\":{\"input_tokens\":3,\"output_tokens\":0}}}\n\n"
            ."event: content_block_start\ndata: {\"type\":\"content_block_start\",\"index\":0,\"content_block\":{\"type\":\"text\",\"text\":\"\"}}\n\n"
            ."event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\"{$text}\"}}\n\n"
            ."event: content_block_stop\ndata: {\"type\":\"content_block_stop\",\"index\":0}\n\n"
            ."event: message_delta\ndata: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"{$stop}\"},\"usage\":{\"output_tokens\":1}}\n\n"
            ."event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n", 200, ['Content-Type' => 'text/event-stream']);
    }

    public function test_a_call_result_carries_the_providers_finish_reason(): void
    {
        $this->claude('A whole answer.', 'end_turn');
        $whole = app(Router::class)->text($this->pro(), 'helper.title', ['text' => 'a'], 'hi');
        $this->assertSame('stop', $whole->finishReason);
        $this->assertFalse($whole->cutOff());

        $this->claude('Half an ans', 'max_tokens');
        $cut = app(Router::class)->text($this->pro(), 'helper.title', ['text' => 'a'], 'hi');
        $this->assertSame('length', $cut->finishReason);
        $this->assertTrue($cut->cutOff());
    }

    public function test_a_stream_cut_by_the_provider_yields_an_error_event_before_the_end(): void
    {
        $this->claudeStream('Half an ans', 'max_tokens');

        $stream = app(Router::class)->stream($this->pro(), 'chat.answer', ['language' => 'English'], 'hi');
        $events = iterator_to_array($stream, false);

        $this->assertSame('Half an ans', $events[0]);
        $this->assertSame('error', $events[1]['type']);
        $this->assertStringContainsString('length limit', $events[1]['message']);
        $this->assertTrue($stream->getReturn()->cutOff());
    }

    public function test_a_stream_that_ends_normally_yields_no_error(): void
    {
        $this->claudeStream('All of it.', 'end_turn');

        $stream = app(Router::class)->stream($this->pro(), 'chat.answer', ['language' => 'English'], 'hi');

        $this->assertSame(['All of it.'], iterator_to_array($stream, false));
        $this->assertSame('stop', $stream->getReturn()->finishReason);
    }

    public function test_a_stream_the_router_stops_at_the_estimate_is_told_the_same_way(): void
    {
        config(['models.max_output_tokens_by_provider' => ['anthropic' => 1]]);
        $this->claudeStream(str_repeat('word ', 200), 'end_turn');

        $stream = app(Router::class)->stream($this->pro(), 'chat.answer', ['language' => 'English'], 'hi');
        $events = iterator_to_array($stream, false);

        $this->assertSame('error', end($events)['type']);
        $this->assertTrue($stream->getReturn()->cutOff());
    }

    public function test_the_chat_endpoint_sends_the_error_event_then_done(): void
    {
        $this->claude('A summary.', 'end_turn');
        $user = $this->pro();
        $notebook = Notebook::create(['user_id' => $user->id, 'title' => 'T']);
        $notebook->sources()->create(['title' => 'S', 'status' => 'pending', 'content' => self::EN]);
        app()->call([new EmbedSource($notebook->sources()->first()->id), 'handle']);
        $this->claudeStream('Half an ans', 'max_tokens');

        $body = $this->actingAs($user)->post("/notebooks/{$notebook->id}/chat", ['question' => 'When did Orion launch?'])->streamedContent();

        $this->assertLessThan(strpos($body, '"done"'), strpos($body, 'length limit'), 'the error comes before done');
    }

    public function test_an_empty_report_fails_the_item_instead_of_finishing_it(): void
    {
        [$user, $item] = $this->itemWithSource();
        RouterAgent::fake(fn () => '   ');

        $this->expectException(UserFacingException::class);
        app(StudioGenerator::class)->generate($item);
    }

    public function test_a_report_cut_by_its_limit_fails_the_item_and_is_never_stored_as_done(): void
    {
        $this->claude('A summary.', 'end_turn');
        [$user, $item] = $this->itemWithSource($this->pro());
        $this->claude('## Briefing, half of i', 'max_tokens');

        try {
            app(StudioGenerator::class)->generate($item);
            $this->fail('A cut report must not finish.');
        } catch (UserFacingException $e) {
            $this->assertStringContainsString('length limit', $e->getMessage());
        }
        $this->assertNotSame('done', $item->fresh()->status);
        $this->assertNull($item->fresh()->content);
    }

    public function test_an_empty_summary_is_not_stored_and_the_failure_is_logged(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function ($e) use (&$logged) {
            $logged[] = $e->message.json_encode($e->context);
        });
        RouterAgent::fake(fn () => '');
        $user = User::factory()->create();
        $notebook = Notebook::create(['user_id' => $user->id, 'title' => 'T']);
        $source = $notebook->sources()->create(['title' => 'S', 'status' => 'pending', 'content' => self::EN]);

        app()->call([new EmbedSource($source->id), 'handle']);

        $this->assertNull($source->fresh()->summary, 'no empty summary is kept');
        $this->assertStringContainsString('"source_id":'.$source->id, implode("\n", $logged));
    }

    /** @return array{0: User, 1: StudioItem} */
    private function itemWithSource(?User $user = null): array
    {
        $user ??= User::factory()->create();
        $notebook = Notebook::create(['user_id' => $user->id, 'title' => 'T']);
        $source = $notebook->sources()->create(['title' => 'S', 'status' => 'pending', 'content' => self::EN]);
        if ($user->effectiveTier() === 'free') {
            RouterAgent::fake(fn () => 'A summary.');
        }
        app()->call([new EmbedSource($source->id), 'handle']);

        return [$user, StudioItem::create(['notebook_id' => $notebook->id, 'user_id' => $user->id, 'kind' => 'briefing', 'title' => 'B', 'status' => 'pending', 'settings' => []])];
    }
}
