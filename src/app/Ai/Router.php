<?php

namespace App\Ai;

use App\Ai\Agents\RouterAgent;
use App\Ai\Exceptions\CapExceeded;
use App\Ai\Exceptions\EmptyImage;
use App\Ai\Exceptions\UnpricedModel;
use App\Models\UsageEvent;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Audio;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Image;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Throwable;

/**
 * The one door. Every model call in the app goes through here, and here it does three things:
 * check the cap (before the provider is called), trace to Langfuse, and write the ledger.
 */
class Router
{
    public function __construct(
        private Ledger $ledger,
        private Pricing $pricing,
        private Tracer $tracer,
        private Prompts $prompts,
        private ElevenLabs $elevenLabs,
    ) {}

    /**
     * A text answer. $kind is 'chat' or 'helper': it picks the model for the user's tier. $schema names a JSON schema in App\Ai\Schemas: the
     * provider then answers in strict structured output (Claude output_config.format, Mistral response_format json_schema), and the text is that JSON.
     * $attachments are images (Laravel\Ai\Files\Image) the model looks at beside the text (D-057); they never reach the trace.
     *
     * @param  array<string, mixed>  $variables  filled into the named prompt
     *
     * @throws CapExceeded
     */
    public function text(
        User $user,
        string $promptName,
        array $variables,
        string $input,
        string $kind = 'chat',
        ?int $notebookId = null,
        string $keyOwner = 'app',
        ?int $maxOutputTokens = null,
        ?string $schema = null,
        array $attachments = [],
    ): CallResult {
        $tier = $user->effectiveTier();
        ['provider' => $provider, 'model' => $model] = config("models.$kind.$tier") ?? config("models.$kind.free");
        $prompt = $this->prompts->get($promptName, $variables);

        // An attached image is input too: the hold counts it (the real cost is whatever the provider reports). Only the text goes to the trace.
        $inTokens = $this->pricing->estimateTokens($prompt->text.$input) + count($attachments) * (int) config('models.image_input_tokens');
        $maxOut = $maxOutputTokens ?? self::maxOutputFor($provider);
        $estimate = $this->pricing->cost($model, $inTokens, $maxOut);

        return $this->through(
            user: $user, name: $promptName, provider: $provider, model: $model, kind: 'chat',
            input: ['system' => $prompt->text, 'user' => $input], notebookId: $notebookId, keyOwner: $keyOwner,
            estimate: $estimate, inTokens: $inTokens, prompt: $prompt, tier: $tier, maxOut: $maxOut,
            call: function () use ($prompt, $input, $provider, $model, $maxOut, $schema, $attachments) {
                // text() answers JSON-only calls (reports, quiz, slides, summaries): no hidden thinking, which took 1,000 to 5,000 of the output tokens.
                // HOW a model is told not to think differs (Sonnet 5.5 refuses "disabled" with a 400), so it is read per model from config.
                $response = (new RouterAgent($prompt->text, $maxOut, (array) (config('models.structured_call_options')[$model] ?? []), $schema, $schema === null ? null : Schemas::schema($schema)))->prompt($input, $attachments, provider: $provider, model: $model, timeout: self::timeout());

                return [$response->text, $response->usage->inputTokens, $response->usage->outputTokens, $response->steps->last()?->finishReason->value];
            },
        );
    }

    /**
     * Speech for one turn of an audio overview. Priced per character; the ledger row says `tts` with the characters used.
     *
     * @throws CapExceeded
     */
    public function speech(User $user, string $text, string $voice, ?int $notebookId = null, string $name = 'studio.audio.speech'): SpeechResult
    {
        ['provider' => $provider, 'model' => $model] = config('models.speech');
        $characters = mb_strlen($text);
        $rate = (int) (config("pricing.speech.$model") ?? config('pricing.speech_default'));
        $cost = (int) ceil($characters * $rate / 1_000_000);

        $open = $this->begin($user, $name, $provider, $model, 'tts', ['characters' => $characters, 'voice' => $voice], $notebookId, 'app', $cost, null, $user->effectiveTier());

        try {
            $audio = Audio::of($text)->voice($voice)->timeout(self::timeout())->generate($provider, $model);
        } catch (Throwable $e) {
            $this->abort($open, $e);

            throw $e;
        }

        $this->ledger->settle($open->reservation, [
            'notebook_id' => $notebookId, 'provider' => $provider, 'model' => $model, 'kind' => 'tts',
            'units' => ['characters' => $characters], 'cost_micro_usd' => $cost, 'key_owner' => 'app',
            'langfuse_trace_id' => $open->traced->traceId(),
        ]);
        $open->traced->end(['characters' => $characters, 'audio' => 'omitted'], $characters, 0, $cost);

        return new SpeechResult($audio->content());
    }

    /**
     * One generated image. The defaults are the notebook icon's (config/images.php); an infographic passes its own name, size, quality, style
     * and model. Priced per image from config/pricing.php: a model, quality or size with no price is refused before the call. The daily image
     * budget is shared by every kind of image. Reserved before, settled after, traced. The prompt holds what the user's notebook is about,
     * so it is never traced or logged: the trace says "omitted". A failure writes a ledger row at cost 0 that names the error class only.
     *
     * @param  string  $name  the call's name in the trace and the ledger (notebook.icon, infographic, ...)
     *
     * @throws CapExceeded|UnpricedModel
     */
    public function image(
        User $user, string $subject, ?int $notebookId = null, string $name = 'notebook.icon',
        ?string $size = null, ?string $quality = null, ?string $style = null, ?string $model = null,
    ): ImageResult {
        $provider = 'openai';
        $model ??= (string) config('images.model');
        $size ??= (string) config('images.size');
        $quality ??= (string) config('images.quality');
        // Model ids hold dots, so the table is read by keys, not by dot notation.
        $cost = ((array) config('pricing.images'))[$model][$quality][$size] ?? null;
        if (! is_int($cost) || $cost <= 0) {
            throw new UnpricedModel($model);
        }

        // The whole app's daily budget for images (icons and infographics share it).
        $today = (int) UsageEvent::query()->where('kind', 'image')->where('created_at', '>=', now()->startOfDay())->sum('cost_micro_usd');
        if ($today + $cost > (int) config('images.daily_cap_micro_usd')) {
            throw new CapExceeded('images');
        }

        $prompt = ($style ?? config('images.style')).'. Subject: '.$subject;
        $open = $this->begin($user, $name, $provider, $model, 'image', ['size' => $size, 'quality' => $quality, 'prompt' => 'omitted (holds what the notebook is about)'], $notebookId, 'app', $cost, null, $user->effectiveTier());

        try {
            $response = Image::of($prompt)->size($size)->quality($quality)->timeout((int) config('images.timeout_seconds'))->generate($provider, $model);
            $generated = $response->images->first();
            $bytes = $generated === null ? '' : (string) base64_decode($generated->image, true);
            if ($bytes === '') {
                throw new EmptyImage('The provider returned no image.');
            }
        } catch (Throwable $e) {
            $this->ledger->settle($open->reservation, [
                'notebook_id' => $notebookId, 'provider' => $provider, 'model' => $model, 'kind' => 'image',
                'units' => ['images' => 0, 'error' => class_basename($e)], 'cost_micro_usd' => 0, 'key_owner' => 'app',
                'langfuse_trace_id' => $open->traced->traceId(),
            ]);
            $open->traced->fail(class_basename($e));

            throw $e;
        }

        $this->ledger->settle($open->reservation, [
            'notebook_id' => $notebookId, 'provider' => $provider, 'model' => $model, 'kind' => 'image',
            'units' => ['images' => 1, 'size' => $size, 'quality' => $quality], 'cost_micro_usd' => $cost, 'key_owner' => 'app',
            'langfuse_trace_id' => $open->traced->traceId(),
        ]);
        $open->traced->end(['images' => 1, 'image' => 'omitted'], 0, 0, $cost);

        return new ImageResult($bytes, $generated->mime());
    }

    /**
     * How long one provider call may take, seconds. laravel/ai's own default is 60: with room to think, Claude takes longer, and a call cut at
     * exactly 60 s failed with ProviderConnectionException, cost nothing and left no usage row. Every job that makes a call has a longer timeout.
     */
    public static function timeout(): int
    {
        return (int) config('ai.call_timeout_seconds');
    }

    /** The output limit of a text call on this provider: its own entry in config/models.php, else the default. The hold and the limit are this one number. */
    public static function maxOutputFor(string $provider): int
    {
        return (int) (config('models.max_output_tokens_by_provider')[$provider] ?? config('models.max_output_tokens'));
    }

    /** What a text call would cost at most: used to reserve a whole job before it starts. */
    public function estimateText(User $user, string $input, int $maxOutputTokens, string $kind = 'chat'): int
    {
        $tier = $user->effectiveTier();
        $model = (string) (config("models.$kind.$tier.model") ?? config("models.$kind.free.model"));

        // 1,000 characters of room for the prompt's own instructions.
        return $this->pricing->cost($model, $this->pricing->estimateTokens($input) + $this->pricing->estimateTokens(str_repeat('x', 1_000)), $maxOutputTokens);
    }

    /**
     * One stretch of a two-host dialogue voiced by ElevenLabs Text to Dialogue (Eleven v3). Priced per character like any
     * speech: reserved before the call, settled after, traced, and refused before the provider if a cap would be crossed.
     *
     * @param  list<array{speaker: string, text: string, voice_id?: string}>  $turns  a turn names its own voice, else the speaker's voice in config
     *
     * @throws CapExceeded
     */
    public function dialogue(User $user, array $turns, ?int $notebookId = null): SpeechResult
    {
        $provider = 'elevenlabs';
        $model = (string) config('elevenlabs.model');
        $inputs = array_map(fn (array $t) => [
            'text' => $t['text'],
            'voice_id' => $t['voice_id'] ?? (string) config('elevenlabs.voices.'.strtolower($t['speaker'])),
        ], $turns);
        $characters = array_sum(array_map(fn (array $t) => mb_strlen($t['text']), $turns));
        $rate = (int) (config("pricing.speech.$model") ?? config('pricing.speech_default'));
        $cost = (int) ceil($characters * $rate / 1_000_000);

        $open = $this->begin($user, 'studio.audio.dialogue', $provider, $model, 'tts', ['characters' => $characters, 'turns' => count($turns)], $notebookId, 'app', $cost, null, $user->effectiveTier());

        try {
            $generated = $this->elevenLabs->dialogue($inputs, $model);
            $audio = $generated['audio'];
        } catch (Throwable $e) {
            $this->abort($open, $e);

            throw $e;
        }

        $this->ledger->settle($open->reservation, [
            'notebook_id' => $notebookId, 'provider' => $provider, 'model' => $model, 'kind' => 'tts',
            // `credits` is what ElevenLabs itself counted (it bills in credits); the dollar cost below is still our per-character price.
            'units' => array_filter(['characters' => $characters, 'credits' => $generated['credits']], fn ($v) => $v !== null), 'cost_micro_usd' => $cost, 'key_owner' => 'app',
            'langfuse_trace_id' => $open->traced->traceId(),
        ]);
        $open->traced->end(['characters' => $characters, 'credits' => $generated['credits'], 'audio' => 'omitted'], $characters, 0, $cost);

        return new SpeechResult($audio);
    }

    /**
     * Embeddings always use the app's key and the fixed embedding model.
     *
     * @param  list<string>  $texts
     * @return array{vectors: array<int, array<float>>, result: CallResult}
     *
     * @throws CapExceeded
     */
    public function embed(User $user, array $texts, ?int $notebookId = null): array
    {
        ['provider' => $provider, 'model' => $model] = config('models.embedding');
        $inTokens = $this->pricing->estimateTokens(implode('', $texts));
        $estimate = $this->pricing->cost($model, $inTokens);
        $vectors = [];

        $result = $this->through(
            user: $user, name: 'embed', provider: $provider, model: $model, kind: 'embed',
            input: ['texts' => count($texts)], notebookId: $notebookId, keyOwner: 'app',
            estimate: $estimate, inTokens: $inTokens, prompt: null, tier: $user->effectiveTier(),
            call: function () use ($texts, $provider, $model, &$vectors) {
                $response = Embeddings::for($texts)->timeout(self::timeout())->generate($provider, $model);
                $vectors = $response->embeddings;

                return ['', $response->usage->inputTokens, 0];
            },
        );

        return ['vectors' => $vectors, 'result' => $result];
    }

    /**
     * A streamed text answer: yields the text as it arrives; the generator's return value is the CallResult.
     * Reserve, trace and settle are the same as in text(). If the reader goes away midway, the call is settled
     * on what was produced so far, so a dropped connection never leaves a hold behind.
     *
     * @param  array<string, string>  $variables
     * @return \Generator<int, string|array{type: string, message: string}, mixed, CallResult> text deltas; an `error` event just before the end when the answer was cut at its limit
     *
     * @throws CapExceeded
     */
    public function stream(
        User $user,
        string $promptName,
        array $variables,
        string $input,
        string $kind = 'chat',
        ?int $notebookId = null,
        string $keyOwner = 'app',
    ): \Generator {
        $tier = $user->effectiveTier();
        ['provider' => $provider, 'model' => $model] = config("models.$kind.$tier") ?? config("models.$kind.free");
        $prompt = $this->prompts->get($promptName, $variables);
        $inTokens = $this->pricing->estimateTokens($prompt->text.$input);
        $maxOut = self::maxOutputFor($provider);
        $estimate = $this->pricing->cost($model, $inTokens, $maxOut);

        $open = $this->begin($user, $promptName, $provider, $model, 'chat', ['system' => $prompt->text, 'user' => $input], $notebookId, $keyOwner, $estimate, $prompt, $tier);

        $state = new StreamState;
        $settle = function () use ($state, $open, $inTokens, $maxOut): void {
            if ($state->settled) {
                return;
            }
            $state->settled = true;
            $state->result = $this->finish($open, $state->text, $state->inputTokens, $state->outputTokens, $inTokens, $maxOut, $state->finishReason);
        };

        // A reader that goes away, or a worker that is killed, still pays for what it streamed: PHP runs shutdown
        // functions even when it stops a script whose client has gone, which a generator's `finally` does not survive.
        register_shutdown_function($settle);

        try {
            $response = (new RouterAgent($prompt->text, $maxOut))->stream($input, provider: $provider, model: $model, timeout: self::timeout());

            foreach ($response as $event) {
                if ($event instanceof TextDelta) {
                    $state->text .= $event->delta;

                    yield $event->delta;

                    // The provider was told where to stop; if it does not, stop reading here.
                    if ($this->pricing->estimateTokens($state->text) > $maxOut) {
                        $state->finishReason = 'length';

                        break;
                    }
                } elseif ($event instanceof StreamEnd) {
                    $state->inputTokens += $event->usage->inputTokens;
                    $state->outputTokens += $event->usage->outputTokens;
                    $state->finishReason = $state->finishReason ?? $event->reason;
                }
            }

            // Never a silent cut (the owner's rule): a reader must be told when the answer stopped at its limit, before `done`.
            if ($state->finishReason === 'length') {
                yield ['type' => 'error', 'message' => 'The answer hit its length limit and may be incomplete. Ask again, or ask for a shorter answer.'];
            }
        } catch (Throwable $e) {
            $state->settled = true;
            $this->abort($open, $e);

            throw $e;
        } finally {
            // The normal end, the reader going away, or the generator being dropped: settle on what we have.
            $settle();
        }

        return $state->result ?? throw new \LogicException('stream ended without a result');
    }

    /**
     * The shared pipeline: reserve, trace, call, settle (or release on failure).
     *
     * @param  callable(): array{0: string, 1: int, 2: int}  $call  returns [text, inputTokens, outputTokens]
     */
    private function through(
        User $user, string $name, string $provider, string $model, string $kind, mixed $input, ?int $notebookId,
        string $keyOwner, int $estimate, int $inTokens, ?PromptVersion $prompt, string $tier, callable $call, ?int $maxOut = null,
    ): CallResult {
        $open = $this->begin($user, $name, $provider, $model, $kind, $input, $notebookId, $keyOwner, $estimate, $prompt, $tier);

        try {
            [$text, $inputTokens, $outputTokens, $finishReason] = $call() + [3 => null];
        } catch (Throwable $e) {
            $this->abort($open, $e);

            throw $e;
        }

        return $this->finish($open, $text, $inputTokens, $outputTokens, $inTokens, $maxOut, $finishReason);
    }

    /** Reserve the money and open the trace. Tracing fails open; the reservation does not. */
    private function begin(
        User $user, string $name, string $provider, string $model, string $kind, mixed $input, ?int $notebookId,
        string $keyOwner, int $estimate, ?PromptVersion $prompt, string $tier,
    ): OpenCall {
        // A call on the user's own key costs the app nothing and counts against no cap.
        $reservation = $keyOwner === 'app'
            ? $this->ledger->reserve($user, $estimate)
            : new Reservation(null, $user->id, 0);

        $traced = $this->tracer->start(
            name: $name, provider: $provider, model: $model, input: $input, user: $user, notebookId: $notebookId,
            metadata: [
                'kind' => $kind, 'tier' => $tier, 'key_owner' => $keyOwner, 'estimated_cost_micro_usd' => $estimate,
                'prompt_name' => $prompt?->name, 'prompt_version' => $prompt?->version, 'prompt_source' => $prompt ? ($prompt->fromRepo ? 'repo' : 'langfuse') : null,
            ],
            prompt: $prompt?->prompt,
        );

        return new OpenCall($reservation, $traced, $provider, $model, $kind, $keyOwner, $notebookId, $prompt);
    }

    private function abort(OpenCall $open, Throwable $e): void
    {
        $this->ledger->release($open->reservation);
        $open->traced->fail(class_basename($e));
    }

    private function finish(OpenCall $open, string $text, int $inputTokens, int $outputTokens, int $estimatedInputTokens, ?int $maxOut = null, ?string $finishReason = null): CallResult
    {
        // A provider that reports no usage is billed at our own estimate, so a call is never free by omission.
        if ($inputTokens === 0 && $outputTokens === 0) {
            $inputTokens = $estimatedInputTokens;
            $outputTokens = $this->pricing->estimateTokens($text);
        }

        // The hold was made against max_tokens. A provider that returns more than it was told to is billed at the limit,
        // so a single call can never settle past what was reserved for its output.
        if ($maxOut !== null && $outputTokens > $maxOut) {
            Log::warning('A provider returned more than max_tokens', ['model' => $open->model, 'returned' => $outputTokens, 'limit' => $maxOut]);
            $outputTokens = $maxOut;
        }

        $cost = $open->keyOwner === 'app' ? $this->pricing->cost($open->model, $inputTokens, $outputTokens) : 0;

        $this->ledger->settle($open->reservation, [
            'notebook_id' => $open->notebookId, 'provider' => $open->provider, 'model' => $open->model, 'kind' => $open->kind,
            'units' => ['input_tokens' => $inputTokens, 'output_tokens' => $outputTokens],
            'cost_micro_usd' => $cost, 'key_owner' => $open->keyOwner,
            'prompt_name' => $open->prompt?->name, 'prompt_version' => $open->prompt?->version,
            'langfuse_trace_id' => $open->traced->traceId(),
        ]);
        $open->traced->end($open->kind === 'embed' ? ['embeddings' => 'omitted'] : $text, $inputTokens, $outputTokens, $cost);

        return new CallResult($text, $inputTokens, $outputTokens, $cost, $open->traced->traceId(), $finishReason);
    }
}
