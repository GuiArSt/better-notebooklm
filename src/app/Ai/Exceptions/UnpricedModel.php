<?php

namespace App\Ai\Exceptions;

use RuntimeException;

/** An image model with no price in config/pricing.php: refused before the provider is called, so nothing is ever free by omission. */
class UnpricedModel extends RuntimeException
{
    public function __construct(public readonly string $model)
    {
        parent::__construct("The model [$model] has no price in config/pricing.php.");
    }
}
