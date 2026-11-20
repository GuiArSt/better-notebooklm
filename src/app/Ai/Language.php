<?php

namespace App\Ai;

/**
 * Which language a text is in, so a prompt can NAME it ("Write in English"). D-035: generated text follows the sources' language unless one is
 * chosen, and a chat answer follows the language of the question. The app never reads the browser's language or the app locale for this
 * (nothing does): a small model left to infer "their language" drifts, Mistral Small wrote the overview of English sources in French.
 */
class Language
{
    /** code => name, the languages a user may choose (and the ones guess() knows). */
    public const NAMES = ['en' => 'English', 'fr' => 'French', 'de' => 'German', 'es' => 'Spanish', 'it' => 'Italian', 'pt' => 'Portuguese', 'nl' => 'Dutch'];

    private const WORDS = [
        'en' => ['the', 'and', 'of', 'to', 'in', 'is', 'that', 'for', 'with', 'are', 'this', 'as', 'on', 'be', 'by', 'it', 'from', 'or', 'an', 'which', 'was', 'not', 'have'],
        'fr' => ['le', 'la', 'les', 'des', 'et', 'est', 'une', 'dans', 'pour', 'que', 'qui', 'sur', 'du', 'pas', 'avec', 'sont', 'au', 'ce', 'par', 'plus', 'ont', 'aux', 'ne', 'à', 'été', 'son', 'ses', 'il', 'elle', 'mais', 'où', 'très', 'tout', 'comme', 'entre', 'sans', 'sous', 'cette', 'ces', 'leur', 'nous', 'vous', 'être', 'avoir', 'fait'],
        'de' => ['der', 'die', 'das', 'und', 'ist', 'nicht', 'mit', 'ein', 'eine', 'von', 'zu', 'den', 'für', 'auf', 'dem', 'sich', 'auch', 'als', 'wird', 'sind', 'bei', 'oder'],
        'es' => ['el', 'la', 'los', 'las', 'de', 'y', 'que', 'en', 'un', 'una', 'es', 'por', 'con', 'para', 'del', 'se', 'no', 'al', 'como', 'más', 'su', 'son'],
        'it' => ['il', 'lo', 'gli', 'di', 'e', 'che', 'in', 'un', 'una', 'è', 'per', 'con', 'non', 'del', 'della', 'sono', 'le', 'si', 'più', 'come', 'nel', 'dei'],
        'pt' => ['o', 'os', 'as', 'de', 'e', 'que', 'em', 'um', 'uma', 'é', 'para', 'com', 'não', 'do', 'da', 'dos', 'se', 'por', 'mais', 'são', 'ao', 'como'],
        'nl' => ['de', 'het', 'een', 'en', 'van', 'is', 'dat', 'op', 'te', 'in', 'voor', 'met', 'niet', 'zijn', 'aan', 'ook', 'als', 'maar', 'bij', 'om', 'dit', 'wordt'],
    ];

    /** Close relatives of the supported languages, detected only to be refused: their text is not forced into Spanish or Portuguese (LA-1). */
    private const OTHER = [
        'Catalan' => ['els', 'amb', 'és', 'aquest', 'aquesta', 'també', 'però', 'això', 'són', 'ens', 'seva', 'molt'],
        'Galician' => ['unha', 'coa', 'dunha', 'tamén', 'máis', 'nunha', 'cos', 'polo', 'pola', 'ao', 'xa', 'moi'],
    ];

    /** The name of a language the text is clearly in, or null when it is too short or too close to call. */
    public static function guess(string $text): ?string
    {
        $code = self::guessCode($text);

        return $code === null ? null : self::NAMES[$code];
    }

    /** The name for a stored code, or null. */
    public static function name(?string $code): ?string
    {
        return $code === null ? null : (self::NAMES[$code] ?? null);
    }

    /** The code (en, fr, ...) of the language of the text's first ~5,000 characters, or null when unsure. */
    public static function guessCode(string $text): ?string
    {
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower(mb_substr($text, 0, 5000)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        // A question is short: three words are enough to call it when the language's own words clearly win.
        $short = count($words) < 12;
        if (count($words) < 3) {
            return null;
        }
        $counts = array_count_values($words);

        $scores = [];
        foreach (self::WORDS as $code => $list) {
            $scores[$code] = array_sum(array_map(fn ($w) => $counts[$w] ?? 0, $list));
        }
        arsort($scores);
        $top = array_key_first($scores);
        $first = $scores[$top];
        $second = array_values($scores)[1] ?? 0;

        // At least a tenth of the words are the language's own common words, and the lead over the next language is clear.
        // A close relative the app does not know (Catalan, Galician) must not be called Spanish or Portuguese: no language is better than a wrong one.
        foreach (self::OTHER as $markers) {
            $hits = array_sum(array_map(fn ($w) => $counts[$w] ?? 0, array_slice($markers, 0, 8)));
            if ($hits >= 3 && $hits >= count($words) * 0.02) {
                return null;
            }
        }

        $needed = $short ? 2 : max(6, count($words) * 0.10);
        // Languages share some small words ("de", "que"): the winner must lead by a clear margin, not just by a hair.
        if ($first < $needed || ($first - $second) < max(2, $first * 0.15)) {
            return null;
        }

        return $top;
    }

    /** The language most of these codes name (a tie goes to the first), or null when none is known. @param  list<string|null>  $codes */
    public static function majority(array $codes): ?string
    {
        $counts = array_count_values(array_filter($codes));
        if ($counts === []) {
            return null;
        }
        arsort($counts);

        return array_key_first($counts);
    }

    /** True unless the text is clearly in ANOTHER language than the one meant (an unsure guess is not a mismatch). */
    public static function agrees(string $text, string $code): bool
    {
        $guess = self::guessCode($text);

        return $guess === null || $guess === $code;
    }

    /** The language to name in a prompt: the one the user chose, else the text's own, else a plain description. */
    public static function forPrompt(?string $chosen, string $text, string $fallback = 'the language of the passages'): string
    {
        if ($chosen !== null && $chosen !== '' && $chosen !== 'auto') {
            return self::NAMES[$chosen] ?? $chosen;
        }

        return self::guess($text) ?? $fallback;
    }
}
