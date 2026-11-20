<?php

namespace Tests\Feature\Ai;

use App\Ai\Agents\RouterAgent;
use App\Ai\Router;
use App\Ai\Schemas;
use App\Models\User;
use Axyr\Langfuse\LangfuseFacade as Langfuse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files\Image;
use Tests\TestCase;

/** D-057: Claude looks at the picture it briefed. The image rides with the call (Router::text attachments); it counts in the hold and never reaches the trace. */
class AttachmentsTest extends TestCase
{
    use RefreshDatabase;

    private function png(): string
    {
        $im = imagecreatetruecolor(16, 16);
        imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 30));
        ob_start();
        imagepng($im);

        return base64_encode((string) ob_get_clean());
    }

    public function test_attachments_reach_the_gateway_and_stay_out_of_the_trace(): void
    {
        Langfuse::fake();
        RouterAgent::fake(fn () => '{"verdict":"good","problems":[]}');
        $b64 = $this->png();

        app(Router::class)->text(User::factory()->create(), 'helper.title', ['text' => 'a'], 'Here is the brief.', attachments: [Image::fromBase64($b64, 'image/png')]);

        RouterAgent::assertPrompted(fn ($p) => $p->attachments->count() === 1 && $p->prompt === 'Here is the brief.');
        $trace = (string) json_encode(Langfuse::getFacadeRoot()->exported());
        $this->assertStringContainsString('Here is the brief.', $trace, 'the text is traced');
        $this->assertStringNotContainsString(substr($b64, 0, 40), $trace, 'the image never reaches Langfuse');
    }

    public function test_the_claude_request_carries_the_image_block_and_the_review_schema(): void
    {
        config(['ai.providers.anthropic.key' => 'test-key-not-real']);
        Langfuse::fake();
        $seen = null;
        Http::fake(function (Request $request) use (&$seen) {
            $seen = $request->data();

            return Http::response(['id' => 'm', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => '{"verdict":"bad","problems":["a word is misspelt"]}']], 'usage' => ['input_tokens' => 2_200, 'output_tokens' => 30]], 200);
        });

        $result = app(Router::class)->text(
            User::factory()->create(['tier' => 'pro', 'pro_until' => now()->addDay()]), 'helper.title', ['text' => 'a'], 'The brief', schema: 'infographic_review',
            attachments: [Image::fromBase64($this->png(), 'image/png')],
        );

        $this->assertSame('bad', json_decode($result->text, true)['verdict']);
        $blocks = collect($seen['messages'][0]['content'])->pluck('type')->all();
        $this->assertContains('image', $blocks, 'the picture is in the message');
        $this->assertContains('text', $blocks);
        $this->assertSame('json_schema', $seen['output_config']['format']['type']);
    }

    public function test_each_image_adds_its_tokens_to_the_hold_and_no_image_adds_nothing(): void
    {
        Langfuse::fake();
        RouterAgent::fake(fn () => 'ok');
        $user = User::factory()->create();
        $estimates = [];
        Event::listen('eloquent.creating: App\\Models\\UsageEvent', function () {});
        $hold = function (array $attachments) use ($user) {
            $reserved = null;
            DB::listen(function ($q) use (&$reserved) {
                if (str_contains($q->sql, 'insert into "ledger_reservations"')) {
                    $reserved = $q->bindings;
                }
            });
            app(Router::class)->text($user, 'helper.title', ['text' => 'a'], 'x', attachments: $attachments);

            return $reserved;
        };

        $none = $hold([]);
        $two = $hold([Image::fromBase64($this->png(), 'image/png'), Image::fromBase64($this->png(), 'image/png')]);

        $this->assertNotNull($none);
        $this->assertNotNull($two);
        $this->assertGreaterThan($none[1], $two[1], 'two images raise the reserved amount (binding 1 is the amount)');
        $this->assertSame(2_100, (int) config('models.image_input_tokens'), '1536x1024 / 750 = 2,097 tokens an image');
    }

    public function test_the_review_schema_is_a_closed_verdict_and_a_list_of_problems(): void
    {
        $schema = Schemas::schema('infographic_review');

        $this->assertContains('infographic_review', Schemas::NAMES);
        $this->assertSame(['good', 'bad'], $schema['properties']['verdict']['enum']);
        $this->assertSame('array', $schema['properties']['problems']['type']);
        $this->assertFalse($schema['additionalProperties']);
        $this->assertEqualsCanonicalizing(['verdict', 'problems'], $schema['required']);
    }
}
