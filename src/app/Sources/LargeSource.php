<?php

namespace App\Sources;

use App\Ai\Pricing;
use App\Models\User;

/**
 * Heavy sources (D-040). Up to `retrieval.max_source_chars` a source is accepted; above `retrieval.large_source_chars` it is "large":
 * the interface warns, with a page count and what reading it will cost, worked out from the same price config the ledger charges by.
 */
class LargeSource
{
    /** About how many characters make a page when the source has no pages of its own (a pasted text, a web page). */
    public const CHARS_PER_PAGE = 3_000;

    public function __construct(private Pricing $pricing) {}

    public static function isLarge(?int $chars): bool
    {
        return $chars !== null && $chars > (int) config('retrieval.large_source_chars');
    }

    /**
     * The most that reading the source can cost, in micro-USD: embedding all its text, plus its summary (the first
     * `sources.summary_input_chars` characters, and the longest summary the model may write). Priced for this user's tier.
     */
    public function estimate(int $chars, string $tier): int
    {
        $embedding = (string) config('models.embedding.model');
        $text = config("models.chat.$tier") ?? config('models.chat.free');

        $embed = $this->pricing->cost($embedding, $this->pricing->tokensForChars($chars));
        $summary = $this->pricing->cost((string) $text['model'], $this->pricing->tokensForChars(min($chars, (int) config('sources.summary_input_chars')) + 1_000), (int) config('models.max_output_tokens'));

        return $embed + $summary;
    }

    /**
     * The page count: the real one for a PDF, otherwise an estimate from the length.
     *
     * @param  list<array<string, mixed>>|null  $ranges
     * @return array{count: int, estimated: bool}|null
     */
    public static function pages(?array $ranges, ?int $chars): ?array
    {
        if (is_array($ranges) && $ranges !== []) {
            return ['count' => count($ranges), 'estimated' => false];
        }

        return $chars === null ? null : ['count' => max(1, (int) ceil($chars / self::CHARS_PER_PAGE)), 'estimated' => true];
    }

    /**
     * What the interface reads for a source: `large`, `page_count`, `page_count_estimated` and `estimated_cost_micro_usd` (null unless large).
     *
     * @param  list<array<string, mixed>>|null  $ranges
     * @return array{large: bool, page_count: int|null, page_count_estimated: bool, estimated_cost_micro_usd: int|null}
     */
    public function describe(?int $chars, ?array $ranges, string $tier): array
    {
        $pages = self::pages($ranges, $chars);
        $large = self::isLarge($chars);

        return [
            'large' => $large,
            'page_count' => $large ? $pages['count'] : null,
            'page_count_estimated' => $large ? $pages['estimated'] : false,
            'estimated_cost_micro_usd' => $large ? $this->estimate((int) $chars, $tier) : null,
        ];
    }

    /** The refusal for a text over the cap: it always says the cap, never a silent cut. */
    public static function refusal(string $what = 'This text'): string
    {
        return $what.' is longer than the limit of '.number_format((int) config('retrieval.max_source_chars')).' characters.';
    }
}
