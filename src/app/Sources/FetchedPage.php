<?php

namespace App\Sources;

final class FetchedPage
{
    public function __construct(public readonly string $body, public readonly string $contentType) {}
}
