<?php

namespace App\Sources;

final class ParsedSource
{
    /** @param  list<array{page: int, start: int, end: int}>|null  $pages */
    public function __construct(public readonly string $title, public readonly string $text, public readonly ?array $pages = null) {}
}
