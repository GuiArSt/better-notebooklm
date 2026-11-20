<?php

namespace App\Ai\Exceptions;

/**
 * Text that could not be made safe to hand to a model (D-046): it is not valid UTF-8, or the escaping itself failed. The call stops, and nothing
 * unescaped is ever sent. The message is fixed and never holds the text.
 */
class UnsafeSourceText extends UserFacingException
{
    public function __construct(public readonly string $reason = 'invalid')
    {
        parent::__construct('Some of the text could not be read safely, so it was not sent to the model.');
    }
}
