<?php

namespace App\Ai;

final class SpeechResult
{
    public function __construct(public readonly string $audio) {}
}
