<?php

namespace App\Ai;

use Illuminate\Support\Facades\Log;
use Throwable;

use function Sentry\captureException;

/**
 * Failures are logged as the error CLASS and ids only (Argus, notebook 2a68a27). A message or a stack trace can carry what the user typed
 * (a notebook's title, a question, a passage): a provider echoes it, or PHP prints the first characters of a string argument.
 * So nothing from the exception but its class goes into the log, and deploy/php.ini drops the arguments from traces too.
 */
class SafeLog
{
    /** Something went wrong but was handled (a fallback, a retry that did not help): a warning with ids only. @param  array<string, int|string|null>  $ids */
    public static function warning(string $what, array $ids = []): void
    {
        Log::warning($what, $ids);
    }

    /** @param  array<string, int|string|null>  $ids  numbers and fixed words only: never text a user wrote */
    public static function failure(string $where, Throwable $e, array $ids = []): void
    {
        Log::error($where.' failed', ['error' => get_class($e)] + $ids);

        // A handled failure is still an error to watch (D-043). No-op without a DSN; the Scrubber drops the message before it leaves.
        captureException($e);
    }
}
