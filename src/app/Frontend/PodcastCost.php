<?php

namespace App\Frontend;

use App\Ai\Pricing;
use App\Models\User;
use App\Studio\StudioGenerator;

/**
 * The most one audio overview can cost, read from the same config the backend prices with: the speech for the longest
 * allowed script, plus the script itself (a generous guess at its prompt and output size on the user's chat model).
 */
class PodcastCost
{
    public function __construct(private StudioGenerator $studio) {}

    private const SCRIPT_INPUT_TOKENS = 8_000;

    private const SCRIPT_OUTPUT_TOKENS = 2_500;

    public function maxUsd(User $user): float
    {
        // The voice this user would actually hear: ElevenLabs for reviewers, the owner and pro, Mistral for the rest.
        $speechModel = $this->studio->usesElevenLabs($user) ? (string) config('elevenlabs.model') : (string) config('models.speech.model');
        $speechRate = (int) (config("pricing.speech.$speechModel") ?? config('pricing.speech_default'));
        $speech = (int) config('studio.audio_max_chars') * $speechRate / 1_000_000;

        $chatModel = (string) config('models.chat.'.$user->effectiveTier().'.model');
        $rate = app(Pricing::class)->rate($chatModel);
        $script = (self::SCRIPT_INPUT_TOKENS * $rate['input'] + self::SCRIPT_OUTPUT_TOKENS * $rate['output']) / 1_000_000;

        return round(($speech + $script) / 1_000_000, 2);
    }
}
