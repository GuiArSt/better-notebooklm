<?php

namespace App\Ai;

use Axyr\Langfuse\Contracts\PromptInterface;

final class PromptVersion
{
    public function __construct(
        public readonly string $name,
        public readonly int $version,
        public readonly string $text,
        public readonly bool $fromRepo,
        public readonly ?PromptInterface $prompt = null,
    ) {}
}
