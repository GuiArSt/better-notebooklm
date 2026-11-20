<?php

namespace App\Ai;

use App\Models\Passage;
use Laravel\Ai\Embeddings;

/**
 * AI_FAKE=true on a local machine answers without calling any provider, so the whole path (paste, embed, stream, cite)
 * can be seen in a browser with no keys. It never runs outside the local environment.
 */
class DevFake
{
    public static function register(): void
    {
        Embeddings::fake();

        Agents\RouterAgent::fake(function (string $prompt) {
            preg_match_all('/[\p{L}\p{N}]{4,}/u', mb_strtolower($prompt), $words);
            $best = null;
            $bestScore = -1;

            foreach (Passage::query()->latest('id')->limit(200)->get() as $passage) {
                $score = count(array_filter(array_unique($words[0]), fn ($w) => str_contains(mb_strtolower($passage->content), $w)));
                if ($score > $bestScore) {
                    [$best, $bestScore] = [$passage, $score];
                }
            }

            if ($best === null) {
                return 'I have no passages to read.';
            }

            $sentence = trim(preg_split('/(?<=[.!?])\s/u', $best->content)[0] ?? $best->content);

            return "According to your source: {$sentence} [P{$best->id}] This answer comes from the offline dev fake, not a model.";
        });
    }
}
