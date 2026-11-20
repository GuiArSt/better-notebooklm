<?php

namespace App\Studio;

/**
 * The brief the text model writes and the image model draws from (D-045): a title and a few points with their numbers, each with the passages
 * that prove it. The checker keeps only passage ids the model was shown (an invented one is dropped) and refuses what does not fit; the
 * generator makes ONE repair call with these words, then fails cleanly. Nothing is cut or guessed.
 */
class InfographicBrief
{
    public const MIN_POINTS = 4;

    /**
     * @param  list<int>  $shownIds  the passages the model was shown
     * @return array{0: array{title: string, points: list<array{heading: string, figure: string, text: string, refs: list<string>}>}|null, 1: list<string>} the brief, and the problems with it
     */
    public static function check(string $text, array $shownIds, int $points): array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        $data = ($start !== false && $end !== false) ? json_decode(substr($text, $start, $end - $start + 1), true) : null;
        if (! is_array($data) || ! is_array($data['points'] ?? null)) {
            return [null, ['The answer was not valid JSON with a title and points. Answer with the JSON object only.']];
        }

        $problems = [];
        $clean = fn (mixed $v): string => is_string($v) ? trim((string) preg_replace('/\s+/u', ' ', strip_tags($v))) : '';
        $limit = function (string $name, string $value, string $key) use (&$problems): void {
            $hard = (int) config("infographics.{$key}_hard_max") ?: (int) floor((int) config("infographics.{$key}_max") * 2.5);
            if (mb_strlen($value) > $hard) {
                $problems[] = "$name is over {$key}_hard_max ($hard characters): make it shorter.";
            }
        };

        $title = $clean($data['title'] ?? null);
        if ($title === '') {
            $problems[] = 'The title is missing.';
        }
        $limit('The title', $title, 'title');

        $out = [];
        foreach (array_slice(array_values($data['points']), 0, $points) as $i => $p) {
            $n = $i + 1;
            $p = is_array($p) ? $p : [];
            [$heading, $figure, $body] = [$clean($p['heading'] ?? null), $clean($p['figure'] ?? null), $clean($p['text'] ?? null)];
            $refs = [];
            foreach ((array) ($p['refs'] ?? []) as $ref) {
                if (is_string($ref) && preg_match('/^P(\d+)$/', $ref, $m) && in_array((int) $m[1], $shownIds, true)) {
                    $refs[] = $ref; // an id the model was not shown is dropped
                }
            }
            if ($heading === '' || $body === '') {
                $problems[] = "Point $n needs a heading and a text.";
            }
            if ($refs === []) {
                $problems[] = "Point $n cites no passage you were shown: cite the ids that prove it.";
            }
            $limit("Point $n heading", $heading, 'heading');
            $limit("Point $n figure", $figure, 'figure');
            $limit("Point $n text", $body, 'text');
            $out[] = ['heading' => $heading, 'figure' => $figure, 'text' => $body, 'refs' => array_values(array_unique($refs))];
        }
        if (count($out) < self::MIN_POINTS) {
            $problems[] = 'There are '.count($out)." points: write $points.";
        }

        return [$problems === [] ? ['title' => $title, 'points' => $out] : null, $problems];
    }
}
