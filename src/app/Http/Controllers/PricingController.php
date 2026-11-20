<?php

namespace App\Http\Controllers;

use App\Ai\Pricing;
use App\Auth\GrantPro;
use App\Frontend\UsageMeter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Free and pro. Pro is the models' cost × 1.5 (D-004). No money moves: this is a demo (D-007). */
class PricingController extends Controller
{
    public const MARKUP = 1.5;

    public function show(Request $request, UsageMeter $meter): Response
    {
        $models = collect((array) config('pricing.models'))->map(fn (array $rate, string $name) => [
            'model' => $name,
            'cost_in' => $rate['input'] / 1_000_000,
            'cost_out' => $rate['output'] / 1_000_000,
            'pro_in' => round($rate['input'] * self::MARKUP / 1_000_000, 4),
            'pro_out' => round($rate['output'] * self::MARKUP / 1_000_000, 4),
        ])->values();

        // Speech is the dearest thing a user does: free members hear Mistral's voice, pro (and reviewers) ElevenLabs'.
        $episodeChars = (int) config('studio.audio_max_chars');
        $voice = fn (string $tier, string $model, string $name) => [
            'tier' => $tier,
            'voice' => $name,
            'model' => $model,
            'cost_per_1k_chars' => $this->speechRate($model) / 1_000_000 / 1_000,
            'cost_per_episode' => round($this->speechRate($model) * $episodeChars / 1_000_000 / 1_000_000, 3),
            'pro_per_1k_chars' => round($this->speechRate($model) * self::MARKUP / 1_000_000 / 1_000, 4),
            'pro_per_episode' => round($this->speechRate($model) * self::MARKUP * $episodeChars / 1_000_000 / 1_000_000, 3),
        ];

        $user = $request->user();
        $chatModel = fn (string $tier) => (string) config("models.chat.$tier.model");
        // A typical question: about 3,000 tokens of sources in and 400 tokens out. A 20-question chat, at cost, on each tier's model.
        $chatCost = function (string $tier): float {
            $rate = app(Pricing::class)->rate((string) config("models.chat.$tier.model"));

            return round(20 * (3_000 * $rate['input'] + 400 * $rate['output']) / 1_000_000 / 1_000_000, 3);
        };

        return Inertia::render('pricing', [
            // The purchase, not the role: a reviewer is pro by role and is still offered the test purchase.
            'tier' => $user->hasProPurchase() ? 'pro' : 'free',
            'pro_until' => $user->getAttribute('pro_until')?->toIso8601String(),
            'checkout' => in_array($request->query('checkout'), ['success', 'cancelled', 'unavailable'], true) ? $request->query('checkout') : null,
            'stripe_ready' => (string) config('billing.secret') !== '',
            'end_date' => (string) config('legal.end_date'),
            'plans' => [
                'free' => ['chat_model' => $chatModel('free'), 'voice' => 'Mistral', 'cap' => (int) config('ledger.user_caps.free') / 1_000_000],
                'pro' => [
                    'chat_model' => $chatModel('pro'),
                    'voice' => 'ElevenLabs',
                    'cap' => (int) config('ledger.user_caps.pro') / 1_000_000,
                    'price' => (int) config('billing.price.amount') / 100,
                    'currency' => strtoupper((string) config('billing.price.currency')),
                    'days' => GrantPro::DAYS,
                ],
            ],
            'example_chat' => ['questions' => 20, 'free_usd' => $chatCost('free'), 'pro_usd' => $chatCost('pro'), 'pro_list_usd' => round($chatCost('pro') * self::MARKUP, 3)],
            'episode_chars' => $episodeChars,
            'speech' => [
                $voice('free', (string) config('models.speech.model'), 'Mistral'),
                $voice('pro', (string) config('elevenlabs.model'), 'ElevenLabs'),
            ],
            'usage' => $meter->for($request->user()),
            'markup' => self::MARKUP,
            'caps' => [
                'free' => (int) config('ledger.user_caps.free') / 1_000_000,
                'pro' => (int) config('ledger.user_caps.pro') / 1_000_000,
            ],
            'models' => $models,
        ]);
    }

    private function speechRate(string $model): int
    {
        return (int) (config("pricing.speech.$model") ?? config('pricing.speech_default'));
    }
}
