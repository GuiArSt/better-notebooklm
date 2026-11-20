<?php

namespace Tests\Feature\Ai;

use App\Ai\Agents\RouterAgent;
use App\Ai\Router;
use App\Models\User;
use Axyr\Langfuse\LangfuseFacade as Langfuse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Enums\Lab;
use Tests\TestCase;

/** Claude's hidden thinking ate 1,000 to 5,000 output tokens of JSON-only calls (D-047: the real fix). text() turns it off; streamed chat is as before. */
class ThinkingOffTest extends TestCase
{
    use RefreshDatabase;

    private function pro(): User
    {
        return User::factory()->create(['tier' => 'pro', 'pro_until' => now()->addDay()]);
    }

    private function fakeAnthropic(): void
    {
        config(['ai.providers.anthropic.key' => 'test-key-not-real']);
        Langfuse::fake();
        Http::fake(['api.anthropic.com/*' => Http::response([
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => '{"ok": true}']], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ], 200)]);
    }

    public function test_a_text_call_on_sonnet_sends_between_tools_and_low_effort_and_never_disabled(): void
    {
        $this->fakeAnthropic();

        $result = app(Router::class)->text($this->pro(), 'helper.title', ['text' => 'a'], 'hi');

        $this->assertSame('{"ok": true}', $result->text);
        Http::assertSent(function (Request $r) {
            $body = $r->data();

            return str_contains($r->url(), 'api.anthropic.com') && ($body['thinking'] ?? null) === ['type' => 'between_tools'] && ($body['output_config']['effort'] ?? null) === 'low' && $body['max_tokens'] === 8000;
        });
    }

    public function test_haiku_gets_disabled_and_a_model_not_listed_gets_nothing(): void
    {
        $this->fakeAnthropic();
        config(['models.chat.pro.model' => 'claude-haiku-5-5']);
        app(Router::class)->text($this->pro(), 'helper.title', ['text' => 'a'], 'hi');
        Http::assertSent(fn (Request $r) => ($r->data()['thinking'] ?? null) === ['type' => 'disabled'] && ($r->data()['output_config']['effort'] ?? null) === 'low');

        Http::fake(['api.anthropic.com/*' => Http::response(['id' => 'm', 'type' => 'message', 'role' => 'assistant', 'model' => 'x', 'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'ok']], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]], 200)]);
        config(['models.chat.pro.model' => 'claude-some-new-model']);
        app(Router::class)->text($this->pro(), 'helper.title', ['text' => 'a'], 'hi');
        Http::assertSent(fn (Request $r) => $r->data()['model'] === 'claude-some-new-model' && ! array_key_exists('thinking', $r->data()) && ! array_key_exists('output_config', $r->data()));
    }

    public function test_the_agent_sends_provider_options_only_to_anthropic(): void
    {
        $agent = new RouterAgent('s', 10, ['thinking' => ['type' => 'between_tools']]);

        $this->assertSame(['thinking' => ['type' => 'between_tools']], $agent->providerOptions(Lab::Anthropic));
        $this->assertSame(['thinking' => ['type' => 'between_tools']], $agent->providerOptions('anthropic'));
        $this->assertSame([], $agent->providerOptions(Lab::Mistral), 'Mistral is untouched');
        $this->assertSame([], $agent->providerOptions('mistral'));
        $this->assertSame([], (new RouterAgent('s', 10))->providerOptions(Lab::Anthropic), 'a call that did not ask keeps the provider default');
    }

    public function test_the_config_never_lists_disabled_for_sonnet(): void
    {
        $this->assertSame('between_tools', config('models.structured_call_options')['claude-sonnet-5-5']['thinking']['type'], 'the real API answers 400 to disabled on Sonnet 5.5');
    }

    public function test_the_streamed_chat_answer_does_not_turn_thinking_off(): void
    {
        $this->fakeAnthropic();
        Http::fake(['api.anthropic.com/*' => Http::response(
            "event: message_start\ndata: {\"type\":\"message_start\",\"message\":{\"id\":\"m\",\"usage\":{\"input_tokens\":3,\"output_tokens\":0}}}\n\n"
            ."event: content_block_start\ndata: {\"type\":\"content_block_start\",\"index\":0,\"content_block\":{\"type\":\"text\",\"text\":\"\"}}\n\n"
            ."event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\"Hello\"}}\n\n"
            ."event: content_block_stop\ndata: {\"type\":\"content_block_stop\",\"index\":0}\n\n"
            ."event: message_delta\ndata: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"end_turn\"},\"usage\":{\"output_tokens\":2}}\n\n"
            ."event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n", 200, ['Content-Type' => 'text/event-stream'])]);

        $stream = app(Router::class)->stream($this->pro(), 'chat.answer', ['language' => 'English'], 'hi');
        foreach ($stream as $delta) {
            // drained
        }

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.anthropic.com') && ! array_key_exists('thinking', $r->data()) && $r->data()['max_tokens'] === 8000);
    }

    public function test_a_mistral_text_call_is_unchanged(): void
    {
        Langfuse::fake();
        RouterAgent::fake(fn () => 'ok');

        app(Router::class)->text(User::factory()->create(), 'helper.title', ['text' => 'a'], 'hi');

        RouterAgent::assertPrompted(fn ($p) => $p->agent->maxTokens() === 1024 && $p->agent->providerOptions('mistral') === []);
    }
}
