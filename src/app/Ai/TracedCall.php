<?php

namespace App\Ai;

use Axyr\Langfuse\Dto\Usage;
use Axyr\Langfuse\Enums\ObservationLevel;
use Axyr\Langfuse\LangfuseFacade as Langfuse;
use Axyr\Langfuse\Objects\LangfuseGeneration;
use Axyr\Langfuse\Objects\LangfuseTrace;
use Throwable;

/**
 * One model call in Langfuse: a trace with one generation. Tracing fails open: if Langfuse is down or
 * throws, the error is logged and the call goes on. A model call never depends on its trace.
 */
class TracedCall
{
    public function __construct(private ?LangfuseTrace $trace = null, private ?LangfuseGeneration $generation = null) {}

    /** What the Router uses when tracing could not start. */
    public static function untraced(): self
    {
        return new self;
    }

    public function traceId(): ?string
    {
        return $this->trace?->getId();
    }

    public function end(mixed $output, int $inputTokens, int $outputTokens, int $costMicroUsd): void
    {
        $this->safely(function () use ($output, $inputTokens, $outputTokens, $costMicroUsd) {
            $this->generation?->end(
                output: $output,
                usage: new Usage(input: $inputTokens, output: $outputTokens, total: $inputTokens + $outputTokens, totalCost: $costMicroUsd / 1_000_000),
            );
            $this->trace?->end(output: $output);
        });
        $this->flushInWorkers();
    }

    public function fail(string $message): void
    {
        $this->safely(function () use ($message) {
            $this->generation?->end(statusMessage: $message, level: ObservationLevel::ERROR);
            $this->trace?->end();
        });
        $this->flushInWorkers();
    }

    // A web request flushes when it terminates; a queue worker never terminates, so it flushes after each call.
    private function flushInWorkers(): void
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            $this->safely(fn () => Langfuse::flush());
        }
    }

    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            SafeLog::failure('Langfuse trace', $e);
        }
    }
}
