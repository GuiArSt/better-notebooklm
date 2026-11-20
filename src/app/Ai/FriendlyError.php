<?php

namespace App\Ai;

use App\Ai\Exceptions\CapExceeded;
use App\Ai\Exceptions\UserFacingException;
use Illuminate\Http\Client\ConnectionException;
use JeffersonGoncalves\SsrfGuard\Exceptions\BlockedHostException;
use JeffersonGoncalves\SsrfGuard\Exceptions\ResponseTooLargeException;
use Throwable;

/** What a person may read about a failure: our own messages, or a plain sentence. Never an exception's own text. */
class FriendlyError
{
    public const GENERIC = 'Something went wrong while working on this. Please try again.';

    public static function message(Throwable $e): string
    {
        return match (true) {
            $e instanceof CapExceeded, $e instanceof UserFacingException => $e->getMessage(),
            $e instanceof BlockedHostException => 'That address cannot be added: it must be a public web page.',
            $e instanceof ResponseTooLargeException => 'That page is too large to add.',
            // A page that does not answer in time or cannot be reached (found live: gnu.org timed out after 10 seconds).
            $e instanceof ConnectionException => 'That page could not be reached, or it took too long to answer. Try again, or paste its text.',
            default => self::GENERIC,
        };
    }
}
