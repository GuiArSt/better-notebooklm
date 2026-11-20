<?php

namespace App\Ai\Exceptions;

use RuntimeException;

/** Thrown before any provider is called. `scope` is "user", "global" or "images" (the daily icon budget). */
class CapExceeded extends RuntimeException
{
    public function __construct(public readonly string $scope)
    {
        parent::__construct(match ($scope) {
            'global' => 'The app has reached its monthly spending limit.',
            'images' => 'The app has reached its daily limit for generated icons.',
            default => 'You have reached your monthly usage limit.',
        });
    }
}
