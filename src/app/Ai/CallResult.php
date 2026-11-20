<?php

namespace App\Ai;

final class CallResult
{
    public function __construct(
        public readonly string $text,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $costMicroUsd,
        public readonly ?string $traceId,
        /** The provider's own reason for stopping: stop, length (cut by the output limit), content_filter... null when it did not say. */
        public readonly ?string $finishReason = null,
    ) {}

    /** True when the answer stopped at its output limit: what we hold is not the whole answer. */
    public function cutOff(): bool
    {
        return $this->finishReason === 'length';
    }
}
