<?php

namespace App\Studio;

/**
 * Counts are structure, not wording: a model that writes seven bullets or fourteen slides is not asked to rewrite, the extras are dropped
 * before the contract is checked (the first MAX_SLIDES slides, and the list maxima of SlideContract::templates()). Whole items go; no sentence is ever
 * shortened here. A closing slide is kept: the last slide of the original takes the last place unless it is a section. What was dropped is
 * returned so it can be recorded in the item's settings. Lengths are NOT touched: the model fixes those (SlideContract refuses them).
 */
class SlideTrim
{
    /**
     * @param  mixed  $deck  the decoded JSON (anything else is returned as it is)
     * @return array{0: mixed, 1: array{slides: int, bullets: int, column_items: int, steps: int}}
     */
    public static function apply(mixed $deck): array
    {
        $trimmed = ['slides' => 0, 'bullets' => 0, 'column_items' => 0, 'steps' => 0];
        if (! is_array($deck) || ! is_array($deck['slides'] ?? null) || ! array_is_list($deck['slides'])) {
            return [$deck, $trimmed];
        }

        $slides = $deck['slides'];
        $max = SlideContract::MAX_SLIDES;
        if (count($slides) > $max) {
            $closing = null;
            for ($i = count($slides) - 1; $i >= $max - 1; $i--) {
                if (is_array($slides[$i]) && ($slides[$i]['template'] ?? null) !== 'section') {
                    $closing = $slides[$i];
                    break;
                }
            }
            $trimmed['slides'] = count($slides) - $max;
            $slides = array_slice($slides, 0, $max - ($closing !== null ? 1 : 0));
            if ($closing !== null) {
                $slides[] = $closing;
            }
        }

        foreach ($slides as &$slide) {
            if (! is_array($slide) || ! is_array($slide['fields'] ?? null)) {
                continue;
            }
            $template = $slide['template'] ?? null;
            $fields = &$slide['fields'];
            // The maxima are read from the contract itself, so the trim and the check cannot disagree.
            $spec = SlideContract::templates()[$template] ?? [];
            if ($template === 'bullets') {
                self::cap($fields, 'items', $spec['items']['list'][1], $trimmed['bullets']);
            } elseif ($template === 'steps') {
                self::cap($fields, 'steps', $spec['steps']['list'][1], $trimmed['steps']);
            } elseif ($template === 'two_columns') {
                foreach (['left', 'right'] as $side) {
                    if (is_array($fields[$side] ?? null)) {
                        self::cap($fields[$side], 'items', $spec[$side]['object']['items']['list'][1], $trimmed['column_items']);
                    }
                }
            }
            unset($fields);
        }
        unset($slide);

        $deck['slides'] = $slides;

        return [$deck, $trimmed];
    }

    /** @param  array<string, mixed>  $holder */
    private static function cap(array &$holder, string $key, int $max, int &$count): void
    {
        if (is_array($holder[$key] ?? null) && array_is_list($holder[$key]) && count($holder[$key]) > $max) {
            $count += count($holder[$key]) - $max;
            $holder[$key] = array_slice($holder[$key], 0, $max);
        }
    }

    /** "trimmed: 2 slides, 3 bullets" for the settings, or null when nothing was dropped. */
    public static function note(array $trimmed): ?string
    {
        $parts = [];
        foreach (['slides' => 'slides', 'bullets' => 'bullets', 'column_items' => 'column items', 'steps' => 'steps'] as $key => $label) {
            if (($trimmed[$key] ?? 0) > 0) {
                $parts[] = $trimmed[$key].' '.$label;
            }
        }

        return $parts === [] ? null : 'trimmed: '.implode(', ', $parts);
    }
}
