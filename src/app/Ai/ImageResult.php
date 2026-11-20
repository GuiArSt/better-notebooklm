<?php

namespace App\Ai;

/** A generated image: the raw bytes and what the call cost. */
final class ImageResult
{
    public function __construct(public readonly string $bytes, public readonly string $mime) {}
}
