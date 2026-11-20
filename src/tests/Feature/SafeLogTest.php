<?php

namespace Tests\Feature;

use App\Ai\Agents\RouterAgent;
use App\Ai\Prompts;
use App\Models\Notebook;
use App\Models\StudioItem;
use App\Models\User;
use App\Sources\EmbedSource;
use Axyr\Langfuse\LangfuseFacade as Langfuse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Image;
use Tests\TestCase;

/** Argus (notebook 2a68a27): a failure logs the error class and ids, never what the user typed. */
class SafeLogTest extends TestCase
{
    use RefreshDatabase;

    private const TITLE = 'Secret Project Orchid';

    private const QUESTION_TEXT = 'The Orion Project launched in March 2031.';

    /** @var list<array{message: string, context: array<string, mixed>}> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Langfuse::fake();
        Embeddings::fake();
        Event::listen(MessageLogged::class, fn (MessageLogged $e) => $this->logged[] = ['message' => $e->message, 'context' => $e->context]);
    }

    private function everythingLogged(): string
    {
        return json_encode($this->logged, JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '';
    }

    public function test_a_failed_icon_logs_the_class_and_the_notebook_id_and_no_title(): void
    {
        config(['images.enabled' => true, 'ai.providers.openai.key' => 'sk-test-not-real']);
        Image::fake([fn () => throw new \RuntimeException('the provider said no to '.self::TITLE)]);

        $notebook = Notebook::create(['user_id' => User::factory()->create()->id, 'title' => self::TITLE]);

        $log = $this->everythingLogged();
        $this->assertStringContainsString('Notebook icon failed', $log);
        $this->assertStringContainsString('RuntimeException', $log);
        $this->assertStringContainsString('"notebook_id":'.$notebook->id, $log);
        $this->assertStringNotContainsString('Orchid', $log);
        $this->assertStringNotContainsString('Secret Project', $log);
        $this->assertSame('failed', $notebook->fresh()->icon_status);
    }

    public function test_a_failed_quiz_logs_the_class_and_ids_and_no_source_text_or_prompt(): void
    {
        $user = User::factory()->create();
        $notebook = Notebook::create(['user_id' => $user->id, 'title' => self::TITLE]);
        $source = $notebook->sources()->create(['title' => 'Orion notes', 'status' => 'pending', 'content' => self::QUESTION_TEXT]);
        RouterAgent::fake(fn () => 'ok');
        app()->call([new EmbedSource($source->id), 'handle']);
        // The provider fails and echoes the prompt it was given (which holds the user's passages).
        RouterAgent::fake(function (string $prompt) {
            throw new \RuntimeException('bad request: '.$prompt);
        });

        $item = StudioItem::findOrFail($this->actingAs($user)->postJson("/notebooks/{$notebook->id}/studio", ['kind' => 'quiz'])->assertStatus(202)->json('id'));

        $log = $this->everythingLogged();
        $this->assertSame('failed', $item->status);
        $this->assertStringContainsString('Studio item failed', $log);
        $this->assertStringContainsString('RuntimeException', $log);
        $this->assertStringContainsString('"item_id":'.$item->id, $log);
        $this->assertStringNotContainsString('Orion Project', $log);
        $this->assertStringNotContainsString('bad request', $log);
        $this->assertStringNotContainsString('Orchid', $log);
    }

    public function test_a_failing_source_embedding_logs_no_passage_text(): void
    {
        $user = User::factory()->create();
        $notebook = Notebook::create(['user_id' => $user->id, 'title' => 'T']);
        $source = $notebook->sources()->create(['title' => 'Orion notes', 'status' => 'pending', 'content' => self::QUESTION_TEXT]);
        Embeddings::fake(fn () => throw new \RuntimeException('cannot embed: '.self::QUESTION_TEXT));

        app()->call([new EmbedSource($source->id), 'handle']);

        $log = $this->everythingLogged();
        $this->assertStringContainsString('Source embedding failed', $log);
        $this->assertStringNotContainsString('Orion Project', $log);
        $this->assertStringNotContainsString('cannot embed', $log);
    }

    public function test_traces_carry_no_call_arguments(): void
    {
        $this->assertMatchesRegularExpression('/^zend\.exception_ignore_args\s*=\s*On$/m', (string) file_get_contents(base_path('../deploy/php.ini')));
    }

    /** HC-5 (Hipparchus): a Langfuse failure is logged (class and prompt name) and the repo copy still answers. */
    public function test_a_langfuse_prompt_failure_is_logged_and_the_repo_copy_answers(): void
    {
        config(['ai.prompts_source' => 'langfuse']); // the failure is on the Langfuse path
        Langfuse::shouldReceive('prompt')->once()->andThrow(new \RuntimeException('langfuse is down'));

        $prompt = app(Prompts::class)->get('chat.answer', ['language' => 'English']);

        $this->assertTrue($prompt->fromRepo);
        $this->assertStringContainsString('Answer in English', $prompt->text);
        $log = $this->everythingLogged();
        $this->assertStringContainsString('Langfuse prompt', $log);
        $this->assertStringContainsString('RuntimeException', $log);
        $this->assertStringContainsString('chat.answer', $log);
        $this->assertStringNotContainsString('langfuse is down', $log);
    }
}
