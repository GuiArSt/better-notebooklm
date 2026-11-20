<?php

namespace App\Studio;

use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * What the Customize step of an infographic may choose (D-035, D-045): orientation, style and level of detail. Language, sources and the
 * description (the `focus`, at most 500 characters) are the common options of StudioOptions. Every value maps to config, never to model text.
 */
class InfographicOptions
{
    public const REFUSAL = 'Infographics are for pro accounts.';

    /** @return array<string, mixed> the rules of the options that belong to this kind */
    public static function rules(): array
    {
        return [
            'orientation' => ['nullable', Rule::in(array_keys((array) config('infographics.orientations')))],
            'style' => ['nullable', Rule::in(array_keys((array) config('infographics.styles')))],
            'detail' => ['nullable', Rule::in(array_keys((array) config('infographics.details')))],
        ];
    }

    /** Pro, reviewer and owner only, and only where the switch is on (off in prod until the OpenAI DPA, Q-028). A 422 for anyone else. */
    public static function require(User $user): void
    {
        if (! config('infographics.enabled')) {
            throw ValidationException::withMessages(['kind' => 'Infographics are not available here yet.']);
        }
        if (! self::allowedFor($user)) {
            throw ValidationException::withMessages(['kind' => self::REFUSAL]);
        }
    }

    public static function allowedFor(User $user): bool
    {
        return $user->effectiveTier() === 'pro' || in_array($user->getAttribute('role'), (array) config('infographics.roles'), true);
    }

    /**
     * The choices, named: with the defaults filled in, and the canvas and quality they map to.
     *
     * @param  array<string, mixed>  $options
     * @return array{orientation: string, style: string, detail: string, size: string, quality: string, points: int}
     */
    public static function resolve(array $options): array
    {
        $orientation = (string) ($options['orientation'] ?? config('infographics.default_orientation'));
        $detail = (string) ($options['detail'] ?? config('infographics.default_detail'));

        return [
            'orientation' => $orientation,
            'style' => (string) ($options['style'] ?? config('infographics.default_style')),
            'detail' => $detail,
            'size' => (string) config("infographics.orientations.$orientation"),
            'quality' => (string) config("infographics.details.$detail.quality"),
            'points' => (int) config("infographics.details.$detail.points"),
        ];
    }

    /** The picture's price in micro-USD, from the same table the Router charges by. A combination with no price is refused here, before anything is made. */
    public static function imageCost(array $options): int
    {
        $r = self::resolve($options);
        $cost = ((array) config('pricing.images'))[config('infographics.model')][$r['quality']][$r['size']] ?? null; // model ids hold dots: read by keys
        if (! is_int($cost) || $cost <= 0) {
            throw ValidationException::withMessages(['options' => 'That size and detail have no price yet, so they cannot be made.']);
        }

        return $cost;
    }

    /** The style as one fixed text from config, with the guardrail every picture carries. */
    public static function styleText(string $style): string
    {
        return config("infographics.styles.$style.text").' '.config('infographics.guardrail');
    }

    /** What "How this was made" shows, in words. @param  array<string, mixed>  $options */
    public static function labels(array $options): array
    {
        $r = self::resolve($options);

        return ['orientation' => ucfirst($r['orientation']), 'style' => (string) config("infographics.styles.{$r['style']}.label"), 'detail' => ucfirst($r['detail'])];
    }
}
