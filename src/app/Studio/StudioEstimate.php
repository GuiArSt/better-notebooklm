<?php

namespace App\Studio;

use App\Ai\Ledger;
use App\Ai\Pricing;
use App\Models\Notebook;
use App\Models\Passage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * What a Studio item can cost, worked out from the SAME price config the ledger charges by (config/pricing.php) and the SAME hold the
 * generator reserves: an upper bound, not a promise (the real cost is usually lower). Shown in the Customize step beside the user's budget.
 */
class StudioEstimate
{
    public function __construct(private Pricing $pricing, private Ledger $ledger, private StudioGenerator $generator) {}

    /**
     * @param  array{sources: list<array{id: int, title: string}>, options: array<string, mixed>}  $choices  from StudioOptions::normalise
     * @return array<string, mixed>
     */
    public function for(User $user, Notebook $notebook, string $kind, array $choices): array
    {
        $ids = array_map(fn ($s) => $s['id'], $choices['sources']);
        $chars = (int) Passage::query()->where('notebook_id', $notebook->id)->whereIn('source_id', $ids)->sum(DB::raw('char_length(content)'));

        // The generator sends everything when it fits the whole-notebook budget, otherwise the best passages.
        if ($this->pricing->tokensForChars($chars) > (int) config('retrieval.whole_budget_tokens')) {
            $chars = (int) config('studio.passages_per_item') * (int) config('retrieval.chunk_chars');
        }

        // The same hold Router::estimateText makes: the input, 1,000 characters of the prompt's own instructions, and the output limit.
        $family = $kind === 'slides' ? 'slides' : 'chat';
        $model = (string) (config('models.'.$family.'.'.$user->effectiveTier().'.model') ?? config('models.'.$family.'.free.model'));
        $inputTokens = $this->pricing->tokensForChars($chars) + $this->pricing->tokensForChars(1_000);
        $text = fn (int $maxOut) => $this->pricing->cost($model, $inputTokens, $maxOut);

        $estimate = match ($kind) {
            'audio' => $this->audio($user, $text((int) config('studio.audio_script_max_output_tokens')), $choices['options']),
            'mindmap' => $text((int) config('studio.mindmap_max_output_tokens')),
            'quiz' => $text((int) config('studio.quiz_max_output_tokens')),
            'slides' => 2 * $text((int) config('studio.slides_max_output_tokens')), // one repair call is possible
            'infographic' => 2 * $text((int) config('infographics.brief_max_output_tokens')) + $this->infographicPictures($model, $choices['options']), // one repair call is possible
            default => $text((int) config('studio.report_max_output_tokens')),
        };

        $cap = $this->ledger->capFor($user);
        $remaining = max(0, $cap - $this->ledger->spent($user));

        return [
            'kind' => $kind,
            'estimate_micro_usd' => $estimate,
            'estimate_usd' => round($estimate / 1_000_000, 4),
            'remaining_micro_usd' => $remaining,
            'cap_micro_usd' => $cap,
            'within_budget' => $estimate <= $remaining,
            'note' => 'An upper bound from the price list: the real cost is usually lower.',
        ];
    }

    /** The picture and its review, twice at the worst (D-057: bad once means one more drawing and one more look). @param  array<string, mixed>  $options */
    private function infographicPictures(string $model, array $options): int
    {
        $attempts = (int) config('infographics.max_attempts');
        $review = $this->pricing->cost($model, $this->pricing->tokensForChars(2_000) + (int) config('models.image_input_tokens'), (int) config('infographics.review_max_output_tokens'));

        return $attempts * (InfographicOptions::imageCost($options) + $review);
    }

    /** @param  array<string, mixed>  $options */
    private function audio(User $user, int $script, array $options): int
    {
        return $script + $this->generator->speechEstimate($user, Podcast::limits($options)['chars']);
    }
}
