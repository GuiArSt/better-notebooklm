<?php

namespace App\Ai;

/** What a stream has produced so far, shared between the generator and its settle callback. */
final class StreamState
{
    public string $text = '';

    public int $inputTokens = 0;

    public int $outputTokens = 0;

    public ?string $finishReason = null;

    public bool $settled = false;

    public ?CallResult $result = null;
}
