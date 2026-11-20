<?php

namespace App\Ai;

class Pricing
{
    /**
     * The price of a model, micro-USD per million tokens. Read by KEY, not by dot notation: a model id may hold a dot ("x-1.5"), which
     * config("pricing.models.x-1.5") would split and silently miss. A model with no price is billed the default rate, and that is logged.
     *
     * @return array{input: int, output: int}
     */
    public function rate(string $model): array
    {
        $rate = ((array) config('pricing.models'))[$model] ?? null;
        if ($rate === null) {
            SafeLog::warning('No price for the model: the default rate is used', ['model' => $model]);

            return config('pricing.default');
        }

        return $rate;
    }

    /** Cost in micro-USD, rounded up. */
    public function cost(string $model, int $inputTokens, int $outputTokens = 0): int
    {
        $rate = $this->rate($model);

        return (int) ceil(($inputTokens * $rate['input'] + $outputTokens * $rate['output']) / 1_000_000);
    }

    public function estimateTokens(string $text): int
    {
        return $this->tokensForChars(mb_strlen($text));
    }

    /** How many tokens that many characters are taken to be (no need to build a string to count it). */
    public function tokensForChars(int $chars): int
    {
        return (int) ceil($chars / config('ledger.chars_per_token'));
    }
}
