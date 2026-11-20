<?php

namespace App\Ai;

use App\Ai\Exceptions\UserFacingException;
use App\Chat\SourceBlock;
use App\Models\User;

/**
 * Reads the JSON object a model wrote for a JSON-only call. With the provider's structured output (App\Ai\Schemas, D-051) the answer is valid by
 * construction; this is the last net under it, and the net for a path that has no schema. A good answer is read as it is. One that cannot be read
 * gets ONE repair call (through the Router: cap, ledger, trace) that sends the broken answer back with the parser's error; if that cannot be read
 * either, the item ends cleanly. An answer that was cut off is never "repaired": completing it would be inventing it.
 */
class ModelJson
{
    public function __construct(private Router $router) {}

    /**
     * @param  string  $failMessage  what the user is told when it cannot be read (UserFacingException)
     * @return array<mixed>
     *
     * @throws UserFacingException
     */
    public function read(User $user, string $answer, string $failMessage, ?int $notebookId = null, ?string $schema = null, ?int $maxOutputTokens = null, string $kind = 'chat'): array
    {
        [$data, $error] = self::parse($answer);
        if ($data !== null) {
            return $data;
        }
        if (self::cutOff($answer)) {
            SafeLog::warning('A JSON answer was cut off: not repaired', ['notebook_id' => $notebookId]);

            throw new UserFacingException($failMessage);
        }

        // The error is the parser's own word (json_last_error_msg), never text of the model or the user.
        $repair = $this->router->text(
            $user, 'json.repair', [], SourceBlock::sourceData('answer', $answer).SourceBlock::closing("The parser's error: ".$error."\n\nWrite the corrected JSON."),
            $kind, $notebookId, maxOutputTokens: $maxOutputTokens, schema: $schema,
        );
        [$data] = self::parse($repair->text);
        if ($data === null) {
            SafeLog::warning('A JSON answer could not be read, even after one repair call', ['notebook_id' => $notebookId, 'parser' => $error]);

            throw new UserFacingException($failMessage);
        }

        return $data;
    }

    /** @return array{0: array<mixed>|null, 1: string} the object between the outermost braces, and the parser's error when there is none */
    public static function parse(string $text): array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) {
            return [null, 'No JSON object found'];
        }

        $data = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($data) ? [$data, ''] : [null, json_last_error_msg()];
    }

    /** True when the answer opens more objects or arrays than it closes: it stopped in the middle (the string and escape rules of JSON are respected). */
    public static function cutOff(string $text): bool
    {
        $start = strpos($text, '{');
        if ($start === false) {
            return false;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        for ($i = $start, $n = strlen($text); $i < $n; $i++) {
            $c = $text[$i];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($c === '\\') {
                    $escaped = true;
                } elseif ($c === '"') {
                    $inString = false;
                }

                continue;
            }
            if ($c === '"') {
                $inString = true;
            } elseif ($c === '{' || $c === '[') {
                $depth++;
            } elseif ($c === '}' || $c === ']') {
                $depth--;
            }
        }

        return $depth > 0 || $inString;
    }
}
