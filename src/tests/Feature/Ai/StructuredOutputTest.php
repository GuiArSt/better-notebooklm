<?php

namespace Tests\Feature\Ai;

use App\Ai\Agents\RouterAgent;
use App\Ai\Router;
use App\Ai\Schemas;
use App\Models\UsageEvent;
use App\Models\User;
use App\Studio\SlideContract;
use Axyr\Langfuse\LangfuseFacade as Langfuse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** D-051: the JSON kinds ask the provider for strict structured output, through the Router (cap, ledger, trace unchanged). */
class StructuredOutputTest extends TestCase
{
    use RefreshDatabase;

    private const QUIZ = '{"title":"T","questions":[{"question":"Q?","options":["a","b","c","d"],"answer":1,"hint":"h","explanation":"e","refs":["P1"]}]}';

    private function pro(): User
    {
        return User::factory()->create(['tier' => 'pro', 'pro_until' => now()->addDay()]);
    }

    /** @return array<string, mixed> the schema of a kind, as a provider receives it (objects closed, as laravel/ai closes them) */
    private function schemaOf(string $name): array
    {
        return Schemas::schema($name);
    }

    // ---- what reaches the provider ---------------------------------------------------------------------------------

    public function test_claude_gets_output_config_format_json_schema_beside_the_effort_and_thinking_options(): void
    {
        config(['ai.providers.anthropic.key' => 'test-key-not-real']);
        Langfuse::fake();
        $seen = null;
        Http::fake(function (Request $request) use (&$seen) {
            $seen = $request->data();

            return Http::response(['id' => 'm', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => self::QUIZ]], 'usage' => ['input_tokens' => 5, 'output_tokens' => 40]], 200);
        });

        $result = app(Router::class)->text($this->pro(), 'studio.quiz', ['min_questions' => '5', 'max_questions' => '8', 'difficulty' => 'easy', 'language' => 'English'], 'x', schema: 'quiz');

        $this->assertSame(self::QUIZ, $result->text);
        $this->assertSame('json_schema', $seen['output_config']['format']['type']);
        $this->assertSame('low', $seen['output_config']['effort'], 'effort and format coexist');
        $this->assertSame(['type' => 'between_tools'], $seen['thinking']);
        $this->assertFalse($seen['output_config']['format']['schema']['additionalProperties']);
        $this->assertArrayNotHasKey('tools', $seen, 'no forced tool: that is a 400 on Sonnet 5.5');
    }

    public function test_mistral_gets_response_format_json_schema_strict(): void
    {
        config(['ai.providers.mistral.key' => 'test-key-not-real']);
        Langfuse::fake();
        $seen = null;
        Http::fake(function (Request $request) use (&$seen) {
            $seen = $request->data();

            return Http::response(['id' => 'c', 'model' => 'mistral-small-latest', 'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => self::QUIZ], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 40, 'total_tokens' => 45]], 200);
        });

        $result = app(Router::class)->text(User::factory()->create(), 'studio.quiz', ['min_questions' => '5', 'max_questions' => '8', 'difficulty' => 'easy', 'language' => 'English'], 'x', schema: 'quiz');

        $this->assertSame(self::QUIZ, $result->text);
        $this->assertSame('json_schema', $seen['response_format']['type']);
        $this->assertTrue($seen['response_format']['json_schema']['strict'], 'strict is off by default at Mistral');
        $this->assertNotSame('', $seen['response_format']['json_schema']['name']);
        $this->assertFalse($seen['response_format']['json_schema']['schema']['additionalProperties']);
    }

    public function test_a_plain_text_call_asks_for_no_schema_at_either_provider(): void
    {
        config(['ai.providers.anthropic.key' => 'test-key-not-real']);
        Langfuse::fake();
        $seen = null;
        Http::fake(function (Request $request) use (&$seen) {
            $seen = $request->data();

            return Http::response(['id' => 'm', 'type' => 'message', 'role' => 'assistant', 'model' => 'x', 'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Plain words.']], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]], 200);
        });

        app(Router::class)->text($this->pro(), 'helper.title', ['text' => 'a'], 'hi');

        $this->assertArrayNotHasKey('format', $seen['output_config'] ?? []);
        $plain = new RouterAgent('s');
        $this->assertSame([], $plain->providerOptions('mistral'));
        $this->assertSame([], $plain->providerOptions('anthropic'));
    }

    public function test_the_cap_ledger_and_trace_still_apply_and_a_fake_gives_the_json_text_back(): void
    {
        Langfuse::fake();
        RouterAgent::fake(fn () => self::QUIZ);
        $user = User::factory()->create();

        $result = app(Router::class)->text($user, 'studio.quiz', ['min_questions' => '5', 'max_questions' => '8', 'difficulty' => 'easy', 'language' => 'English'], 'x', schema: 'quiz');

        $this->assertSame(self::QUIZ, $result->text);
        $this->assertSame(1, UsageEvent::where('prompt_name', 'studio.quiz')->count());
    }

    public function test_an_unknown_schema_name_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Schemas::for('nope');
    }

    // ---- the schemas themselves ------------------------------------------------------------------------------------

    /** Strict structured output: closed objects, every field required, and none of the keywords Claude answers 400 to. */
    public function test_every_schema_fits_strict_structured_output(): void
    {
        $forbidden = ['minLength', 'maxLength', 'minimum', 'maximum', 'maxItems', 'oneOf', 'not', '$ref', '$defs', 'pattern', 'minItems'];

        foreach (Schemas::NAMES as $name) {
            $schema = $this->schemaOf($name);
            $this->assertSame('object', $schema['type'], $name);
            $this->walk($schema, $name, $forbidden);
        }
    }

    /** @param  array<string, mixed>  $node */
    private function walk(array $node, string $path, array $forbidden): void
    {
        foreach ($forbidden as $key) {
            $this->assertArrayNotHasKey($key, $node, "$path uses $key");
        }
        if (($node['type'] ?? null) === 'object') {
            $this->assertFalse($node['additionalProperties'] ?? null, "$path: object not closed");
            $this->assertEqualsCanonicalizing(array_keys($node['properties'] ?? []), $node['required'] ?? [], "$path: every property is required");
            foreach ($node['properties'] ?? [] as $key => $child) {
                $this->walk($child, "$path.$key", $forbidden);
            }
        }
        if (isset($node['items']) && is_array($node['items'])) {
            $this->walk($node['items'], $path.'[]', $forbidden);
        }
        foreach ($node['anyOf'] ?? [] as $i => $branch) {
            $this->walk($branch, $path.'|'.$i, $forbidden);
        }
    }

    public function test_the_quiz_schema_pins_the_answer_to_four_options_and_the_script_speaker_to_a_or_b(): void
    {
        $quiz = $this->schemaOf('quiz')['properties']['questions']['items']['properties'];
        $this->assertSame([0, 1, 2, 3], $quiz['answer']['enum']);

        $turn = $this->schemaOf('podcast_script')['properties']['turns']['items']['properties'];
        $this->assertSame(['A', 'B'], $turn['speaker']['enum']);
    }

    public function test_the_mind_map_is_written_out_three_levels_deep_without_recursion(): void
    {
        $level1 = $this->schemaOf('mindmap')['properties']['children']['items'];
        $level2 = $level1['properties']['children']['items'];
        $level3 = $level2['properties']['children']['items'];

        $this->assertSame(['label', 'refs', 'children'], array_keys($level1['properties']));
        $this->assertSame(['label', 'refs', 'children'], array_keys($level2['properties']));
        $this->assertSame(['label', 'refs'], array_keys($level3['properties']), 'the third level has no children');
        $this->assertSame((int) config('studio.mindmap_max_depth'), 3, 'the unrolled depth is the configured depth');
    }

    public function test_the_slide_schema_is_one_branch_per_contract_template_with_that_templates_fields(): void
    {
        $branches = $this->schemaOf('slides')['properties']['slides']['items']['anyOf'];

        $this->assertSame(SlideContract::names(), array_map(fn ($b) => $b['properties']['template']['enum'][0], $branches));
        foreach ($branches as $branch) {
            $template = $branch['properties']['template']['enum'][0];
            $this->assertSame(array_keys(SlideContract::templates()[$template]), array_keys($branch['properties']['fields']['properties']), $template);
        }
    }
}
