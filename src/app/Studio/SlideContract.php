<?php

namespace App\Studio;

/**
 * The slide deck contract (D-034), PHP copy. The TypeScript copy is Glyph's, src/resources/js/slides/**; the two are kept identical.
 * The model never writes layout: it picks one of seven templates per slide and fills that template's fields, within hard limits.
 * validate() returns every problem it finds, one plain sentence each, and changes nothing: text over its HARD maximum (250% of the target, D-054) is refused, never cut.
 */
class SlideContract
{
    public const MIN_SLIDES = 6;

    public const MAX_SLIDES = 12;

    /** What the model is asked for when the user chose no number: inside the range, with room on both sides. */
    public const DEFAULT_SLIDES = 8;

    /** The deck title's TARGET (what the prompt asks for). */
    public const TITLE_MAX = 80;

    /**
     * D-054: the `max` of every field is a TARGET, what the prompt asks for. A model cannot count characters, so the checker refuses only beyond the
     * HARD maximum, 250% of the target, whole characters, by integer arithmetic so the TypeScript copy (resources/js/slides/types.ts, hardMax) agrees
     * to the character. Inclusive: a text AT the hard maximum is accepted. Nothing is ever cut.
     */
    public const HARD_PERCENT = 250;

    public static function hardMax(int $target): int
    {
        return intdiv($target * self::HARD_PERCENT, 100);
    }

    public const MAX_SAME_IN_A_ROW = 2;

    public const MIN_DISTINCT_TEMPLATES = 4;

    /**
     * Field specs. A string field is ['max' => n]; a list is ['list' => [min, max], 'item' => spec]; an object is ['object' => [...fields]].
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function templates(): array
    {
        $column = ['object' => ['label' => ['max' => 30], 'items' => ['list' => [2, 4], 'item' => ['max' => 80]]]];

        return [
            'title' => ['heading' => ['max' => 70], 'subheading' => ['max' => 120]],
            'section' => ['heading' => ['max' => 60], 'kicker' => ['max' => 40]],
            'bullets' => ['heading' => ['max' => 60], 'items' => ['list' => [3, 5], 'item' => ['max' => 110]]],
            'two_columns' => ['heading' => ['max' => 60], 'left' => $column, 'right' => $column],
            'big_number' => ['heading' => ['max' => 60], 'number' => ['max' => 12], 'label' => ['max' => 60], 'context' => ['max' => 160]],
            'quote' => ['quote' => ['max' => 220], 'attribution' => ['max' => 80]],
            'steps' => ['heading' => ['max' => 60], 'steps' => ['list' => [3, 5], 'item' => ['object' => ['label' => ['max' => 30], 'text' => ['max' => 90]]]]],
        ];
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::templates());
    }

    /**
     * @param  mixed  $deck  the decoded JSON
     * @return list<string> the problems; empty means the deck is valid
     */
    public static function validate(mixed $deck): array
    {
        if (! is_array($deck)) {
            return ['The answer is not a JSON object with a title and slides.'];
        }

        $errors = [];
        $title = $deck['title'] ?? null;
        if (! is_string($title) || trim($title) === '') {
            $errors[] = 'The deck needs a title (text).';
        } elseif (mb_strlen($title) > self::hardMax(self::TITLE_MAX)) {
            $errors[] = 'The deck title is '.mb_strlen($title).' characters; the limit is '.self::hardMax(self::TITLE_MAX).'.';
        } elseif ($title !== strip_tags($title)) {
            $errors[] = 'The deck title must be plain text, with no markup.';
        }

        $slides = $deck['slides'] ?? null;
        if (! is_array($slides) || ! array_is_list($slides)) {
            return array_merge($errors, ['"slides" must be a list of slides.']);
        }
        if (count($slides) < self::MIN_SLIDES || count($slides) > self::MAX_SLIDES) {
            $errors[] = 'The deck has '.count($slides).' slides; it must have '.self::MIN_SLIDES.' to '.self::MAX_SLIDES.'. '
                .(count($slides) > self::MAX_SLIDES ? 'Remove '.(count($slides) - self::MAX_SLIDES).' of the least important slides.' : 'Add '.(self::MIN_SLIDES - count($slides)).' more slides.');
        }

        $names = [];
        foreach ($slides as $i => $slide) {
            $n = $i + 1;
            if (! is_array($slide)) {
                $errors[] = "Slide $n is not an object.";
                $names[] = null;

                continue;
            }
            $template = $slide['template'] ?? null;
            if (! is_string($template) || ! isset(self::templates()[$template])) {
                $errors[] = "Slide $n has an unknown template; the templates are ".implode(', ', self::names()).'.';
                $names[] = null;

                continue;
            }
            $names[] = $template;

            if (! is_array($slide['fields'] ?? null)) {
                $errors[] = "Slide $n ($template) has no \"fields\" object.";
            } else {
                array_push($errors, ...self::object($slide['fields'], self::templates()[$template], "Slide $n ($template)"));
            }

            $citations = $slide['citations'] ?? [];
            if (! is_array($citations) || ! array_is_list($citations) || array_filter($citations, fn ($c) => ! is_string($c) || ! preg_match('/^P\d+$/', $c)) !== []) {
                $errors[] = "Slide $n: \"citations\" must be a list of passage ids like \"P12\".";
            }
        }

        // The shape of the deck.
        if (($names[0] ?? null) !== 'title') {
            $errors[] = 'The first slide must use the "title" template.';
        }
        if (($names[count($names) - 1] ?? null) === 'section') {
            $errors[] = 'The last slide must not be a "section" slide.';
        }

        // Variety.
        $run = 0;
        $previous = null;
        foreach ($names as $i => $name) {
            $run = ($name !== null && $name === $previous) ? $run + 1 : 1;
            $previous = $name;
            if ($name !== null && $run > self::MAX_SAME_IN_A_ROW) {
                $errors[] = 'Slide '.($i + 1)." repeats the \"$name\" template more than ".self::MAX_SAME_IN_A_ROW.' times in a row.';
            }
        }
        if (count(array_unique(array_filter($names))) < self::MIN_DISTINCT_TEMPLATES) {
            $errors[] = 'The deck must use at least '.self::MIN_DISTINCT_TEMPLATES.' different templates.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, array<string, mixed>>  $spec
     * @return list<string>
     */
    private static function object(array $values, array $spec, string $where): array
    {
        $errors = [];
        foreach ($spec as $field => $rule) {
            $errors = array_merge($errors, self::field($values[$field] ?? null, $rule, "$where, \"$field\""));
        }
        foreach (array_diff(array_keys($values), array_keys($spec)) as $extra) {
            $errors[] = "$where has a field \"$extra\" that this template does not have.";
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return list<string>
     */
    private static function field(mixed $value, array $rule, string $where): array
    {
        if (isset($rule['max'])) {
            if (! is_string($value) || trim($value) === '') {
                return ["$where is missing or empty (text up to {$rule['max']} characters)."];
            }
            if (mb_strlen($value) > self::hardMax($rule['max'])) {
                return ["$where is ".mb_strlen($value).' characters; the limit is '.self::hardMax($rule['max']).'. Shorten it.'];
            }
            if ($value !== strip_tags($value)) {
                return ["$where must be plain text, with no markup."];
            }

            return [];
        }

        if (isset($rule['list'])) {
            [$min, $max] = $rule['list'];
            if (! is_array($value) || ! array_is_list($value)) {
                return ["$where must be a list of $min to $max items."];
            }
            $errors = [];
            if (count($value) < $min || count($value) > $max) {
                $errors[] = "$where has ".count($value)." items; it must have $min to $max. "
                    .(count($value) > $max ? 'Remove '.(count($value) - $max).' of the least important.' : 'Add '.($min - count($value)).' more.');
            }
            foreach ($value as $k => $item) {
                $errors = array_merge($errors, self::field($item, $rule['item'], $where.' item '.($k + 1)));
            }

            return $errors;
        }

        if (! is_array($value) || array_is_list($value) && $value !== []) {
            return ["$where must be an object."];
        }

        return self::object($value, $rule['object'], $where);
    }
}
