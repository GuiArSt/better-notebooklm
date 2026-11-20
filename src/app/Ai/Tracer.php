<?php

namespace App\Ai;

use App\Models\User;
use Axyr\Langfuse\Contracts\PromptInterface;
use Axyr\Langfuse\Dto\GenerationBody;
use Axyr\Langfuse\Dto\TraceBody;
use Axyr\Langfuse\LangfuseFacade as Langfuse;

/**
 * Starts the Langfuse trace for a model call. It records the internal user id (never the email),
 * the notebook as the session, the model, the tier and the prompt version. It never sees a key.
 */
class Tracer
{
    /** @param  array<string, mixed>  $metadata */
    public function start(
        string $name,
        string $provider,
        string $model,
        mixed $input,
        User $user,
        ?int $notebookId,
        array $metadata,
        ?PromptInterface $prompt = null,
    ): TracedCall {
        try {
            return $this->open($name, $provider, $model, $input, $user, $notebookId, $metadata, $prompt);
        } catch (\Throwable $e) {
            SafeLog::failure('Langfuse trace start', $e);

            return TracedCall::untraced();
        }
    }

    /** @param  array<string, mixed>  $metadata */
    private function open(
        string $name, string $provider, string $model, mixed $input, User $user, ?int $notebookId, array $metadata, ?PromptInterface $prompt,
    ): TracedCall {
        $metadata += ['provider' => $provider];

        $trace = Langfuse::trace(new TraceBody(
            name: $name,
            userId: (string) $user->id,
            sessionId: $notebookId ? "notebook-$notebookId" : null,
            input: $input,
            metadata: $metadata,
        ));

        $generation = $trace->generation((new GenerationBody(
            name: $name,
            model: $model,
            input: $input,
            metadata: $metadata,
        ))->withPrompt($prompt));

        return new TracedCall($trace, $generation);
    }
}
