<?php

namespace App\Console\Commands;

use App\Ai\Router;
use App\Models\UsageEvent;
use App\Models\User;
use Axyr\Langfuse\LangfuseFacade as Langfuse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

class AiLiveCheck extends Command
{
    protected $signature = 'ai:live-check {--save= : A folder to keep the two audio clips in (they are never played)}';

    protected $description = 'One real chat call, embedding, speech and dialogue through the Router, with the keys in .env. Local only. Prints no key.';

    public function handle(Router $router): int
    {
        if (! app()->isLocal()) {
            $this->error('Local environment only.');

            return self::FAILURE;
        }
        if (config('ai.fake')) {
            $this->error('AI_FAKE is on: this would check the fake, not a provider. Unset it.');

            return self::FAILURE;
        }

        $user = User::firstOrCreate(
            ['email' => 'live-check@example.test'],
            ['name' => 'Live check', 'password' => Str::random(40)],
        );
        $firstEvent = (int) UsageEvent::max('id');
        $save = $this->option('save') ? rtrim((string) $this->option('save'), '/') : null;
        if ($save) {
            File::ensureDirectoryExists($save);
        }

        $this->step('1. chat (helper model, the free tier)', function () use ($router, $user) {
            $r = $router->text($user, 'helper.title', [], '<text>Notes about the pgvector extension for Postgres.</text>', kind: 'helper');
            $this->line("   answer: \"{$r->text}\"");
        });

        $this->step('2. embedding', function () use ($router, $user) {
            $out = $router->embed($user, ['A first sentence to embed.']);
            $real = count($out['vectors'][0]);
            $this->line("   REAL DIMENSION: $real (configured: ".config('embeddings.dimensions').')'.($real === (int) config('embeddings.dimensions') ? ' — matches' : ' — DOES NOT MATCH'));
        });

        $this->step('3a. Mistral speech', function () use ($router, $user, $save) {
            $s = $router->speech($user, 'Hello, this is a short speech check.', (string) config('models.voices.a'), null, 'live-check.speech');
            $this->line('   audio: '.strlen($s->audio).' bytes, starts with: '.bin2hex(substr($s->audio, 0, 3)));
            if ($save) {
                File::put("$save/mistral-speech.mp3", $s->audio);
            }
        });

        if (filled(config('elevenlabs.key'))) {
            $this->step('3b. ElevenLabs Text to Dialogue (two default voices)', function () use ($router, $user, $save) {
                $d = $router->dialogue($user, [['speaker' => 'A', 'text' => 'Hello, and welcome.'], ['speaker' => 'B', 'text' => 'Glad to be here.']]);
                $this->line('   audio: '.strlen($d->audio).' bytes, starts with: '.bin2hex(substr($d->audio, 0, 3)));
                if ($save) {
                    File::put("$save/elevenlabs-dialogue.mp3", $d->audio);
                }
            });
        } else {
            $this->line('3b. elevenlabs: no key set, skipped.');
        }

        Langfuse::flush();

        $this->newLine();
        $this->info('Ledger rows written by this run (estimate = what was reserved; cost = what was settled; micro-USD):');
        $this->table(
            ['id', 'provider', 'model', 'kind', 'units', 'cost', 'langfuse trace'],
            UsageEvent::where('id', '>', $firstEvent)->orderBy('id')->get()
                ->map(fn ($e) => [$e->id, $e->provider, $e->model, $e->kind, json_encode($e->units), $e->cost_micro_usd, $e->langfuse_trace_id])->all(),
        );

        return self::SUCCESS;
    }

    /** Runs one step; a failure is shown (with any key masked) and the next step still runs. */
    private function step(string $name, callable $run): void
    {
        $this->info($name);
        try {
            $run();
        } catch (Throwable $e) {
            $this->error('   FAILED: '.class_basename($e).': '.$this->masked($e->getMessage()));
        }
    }

    private function masked(string $text): string
    {
        foreach (['ai.providers.mistral.key', 'ai.providers.anthropic.key', 'elevenlabs.key', 'langfuse.secret_key', 'langfuse.public_key'] as $path) {
            $secret = (string) config($path);
            if ($secret !== '') {
                $text = str_replace($secret, '***', $text);
            }
        }

        return Str::limit($text, 500);
    }
}
