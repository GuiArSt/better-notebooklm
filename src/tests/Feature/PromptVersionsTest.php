<?php

namespace Tests\Feature;

use App\Ai\Prompts;
use Axyr\Langfuse\Contracts\PromptInterface;
use Axyr\Langfuse\LangfuseFacade as Langfuse;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** Langfuse's `production` label is shared by dev and prod: a version with other {{variables}} than the repo copy must not answer. */
class PromptVersionsTest extends TestCase
{
    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.prompts_source' => 'langfuse']); // these tests are about the Langfuse path
        Event::listen(MessageLogged::class, fn (MessageLogged $e) => $this->logged[] = $e->message.json_encode($e->context));
    }

    private function langfuseHas(string $template, int $version = 7): void
    {
        $prompt = new class($template, $version) implements PromptInterface
        {
            public function __construct(private string $template, private int $version) {}

            public function getName(): string
            {
                return 'chat.answer';
            }

            public function getVersion(): int
            {
                return $this->version;
            }

            public function getConfig(): array
            {
                return [];
            }

            public function getLabels(): array
            {
                return ['production'];
            }

            public function isFallback(): bool
            {
                return false;
            }

            public function compile(array $variables = []): string|array
            {
                foreach ($variables as $k => $v) {
                    $this->template = str_replace('{{'.$k.'}}', (string) $v, $this->template);
                }

                return $this->template;
            }

            public function toLinkMetadata(): array
            {
                return [];
            }
        };
        Langfuse::shouldReceive('prompt')->andReturn($prompt);
    }

    public function test_a_langfuse_version_with_the_same_variables_answers(): void
    {
        $this->langfuseHas('From Langfuse: answer in {{language}}.');

        $prompt = app(Prompts::class)->get('chat.answer', ['language' => 'German']);

        $this->assertFalse($prompt->fromRepo);
        $this->assertSame(7, $prompt->version);
        $this->assertSame('From Langfuse: answer in German.', $prompt->text);
        $this->assertSame([], $this->logged);
    }

    /** An OLDER version in Langfuse, lacking a variable the repo copy gained (the image's caller passes it, nobody uses it). */
    public function test_a_langfuse_version_missing_a_variable_the_repo_copy_has_is_not_used(): void
    {
        $this->langfuseHas('Old prompt with no variable at all.');

        $prompt = app(Prompts::class)->get('chat.answer', ['language' => 'German']);

        $this->assertTrue($prompt->fromRepo);
        $this->assertSame(0, $prompt->version);
        $this->assertStringContainsString('Answer in German', $prompt->text, 'the repo copy, filled');
        $this->assertStringNotContainsString('Old prompt', $prompt->text);
        $log = implode("\n", $this->logged);
        $this->assertStringContainsString('other {{variables}} than the repo copy', $log);
        $this->assertStringContainsString('"prompt":"chat.answer"', $log);
        $this->assertStringContainsString('"langfuse_version":7', $log);
        $this->assertStringContainsString('"repo_variables":"language"', $log);
        $this->assertStringContainsString('"langfuse_variables":""', $log);
    }

    /** A NEWER version in Langfuse with a variable this image's caller never fills (it would go to the model as literal text). */
    public function test_a_langfuse_version_with_a_variable_the_repo_copy_lacks_is_not_used(): void
    {
        $this->langfuseHas('New prompt: {{language}} and {{persona}}.', 9);

        $prompt = app(Prompts::class)->get('chat.answer', ['language' => 'German']);

        $this->assertTrue($prompt->fromRepo);
        $this->assertStringNotContainsString('{{', $prompt->text);
        $this->assertStringNotContainsString('New prompt', $prompt->text);
        $log = implode("\n", $this->logged);
        $this->assertStringContainsString('"langfuse_version":9', $log);
        $this->assertStringContainsString('"langfuse_variables":"language,persona"', $log);
        $this->assertStringContainsString('"repo_variables":"language"', $log);
    }

    public function test_the_default_source_is_the_repo_and_an_old_langfuse_prompt_never_answers(): void
    {
        config(['ai.prompts_source' => 'repo']);
        $this->assertSame('repo', (string) (require config_path('ai.php'))['prompts_source'], 'the default');
        // Langfuse is not even asked: a call to it would fail this expectation.
        Langfuse::shouldReceive('prompt')->never();

        $prompt = app(Prompts::class)->get('chat.answer', ['language' => 'German']);

        $this->assertTrue($prompt->fromRepo);
        $this->assertSame(0, $prompt->version);
        $this->assertStringContainsString('Answer in German', $prompt->text, 'the repo copy, filled');
    }

    public function test_with_langfuse_as_the_source_a_matching_production_version_answers(): void
    {
        config(['ai.prompts_source' => 'langfuse']);
        $this->langfuseHas('From Langfuse: answer in {{language}}.', 5);

        $prompt = app(Prompts::class)->get('chat.answer', ['language' => 'German']);

        $this->assertFalse($prompt->fromRepo);
        $this->assertSame(5, $prompt->version);
        $this->assertSame('From Langfuse: answer in German.', $prompt->text);
    }

    public function test_every_repo_prompt_renders_from_the_repo_by_default_with_no_langfuse(): void
    {
        config(['ai.prompts_source' => 'repo']);
        Langfuse::shouldReceive('prompt')->never();

        foreach (array_keys(app(Prompts::class)->repoCopies()) as $name) {
            $this->assertTrue(app(Prompts::class)->get($name, [])->fromRepo, $name);
        }
    }
}
