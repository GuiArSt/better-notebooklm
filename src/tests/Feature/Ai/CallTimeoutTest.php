<?php

namespace Tests\Feature\Ai;

use App\Ai\Router;
use App\Models\User;
use Axyr\Langfuse\LangfuseFacade as Langfuse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** laravel/ai's default HTTP timeout is 60 s; Claude with room to think takes longer (items failed at exactly 60 s). Every call carries the app's own. */
class CallTimeoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_default_is_300_seconds_not_60(): void
    {
        $this->assertSame(300, Router::timeout());
    }

    public function test_a_text_call_reaches_the_request_with_the_apps_timeout(): void
    {
        config(['ai.providers.anthropic.key' => 'test-key-not-real']);
        Langfuse::fake();
        $timeout = null;
        Http::fake(function (Request $request, array $options) use (&$timeout) {
            $timeout = $options['timeout'] ?? null;

            return Http::response(['id' => 'm', 'type' => 'message', 'role' => 'assistant', 'model' => 'x', 'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'ok']], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]], 200);
        });

        app(Router::class)->text(User::factory()->create(['tier' => 'pro', 'pro_until' => now()->addDay()]), 'helper.title', ['text' => 'a'], 'hi');

        $this->assertSame(300, (int) $timeout, 'laravel/ai would have used 60');
    }

    public function test_the_timeout_follows_the_config(): void
    {
        config(['ai.call_timeout_seconds' => 123]);

        $this->assertSame(123, Router::timeout());
    }

    public function test_the_streamed_chat_call_carries_it_too(): void
    {
        config(['ai.providers.anthropic.key' => 'test-key-not-real']);
        Langfuse::fake();
        $timeout = null;
        Http::fake(function (Request $request, array $options) use (&$timeout) {
            $timeout = $options['timeout'] ?? null;

            return Http::response(
                "event: message_start\ndata: {\"type\":\"message_start\",\"message\":{\"id\":\"m\",\"usage\":{\"input_tokens\":3,\"output_tokens\":0}}}\n\n"
                ."event: content_block_start\ndata: {\"type\":\"content_block_start\",\"index\":0,\"content_block\":{\"type\":\"text\",\"text\":\"\"}}\n\n"
                ."event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\"Hi\"}}\n\n"
                ."event: content_block_stop\ndata: {\"type\":\"content_block_stop\",\"index\":0}\n\n"
                ."event: message_delta\ndata: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"end_turn\"},\"usage\":{\"output_tokens\":1}}\n\n"
                ."event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n", 200, ['Content-Type' => 'text/event-stream']);
        });

        foreach (app(Router::class)->stream(User::factory()->create(['tier' => 'pro', 'pro_until' => now()->addDay()]), 'chat.answer', ['language' => 'English'], 'hi') as $delta) {
            // drained
        }

        $this->assertSame(300, (int) $timeout);
    }
}
