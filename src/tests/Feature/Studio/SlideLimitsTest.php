<?php

namespace Tests\Feature\Studio;

use App\Studio\SlideContract;
use Tests\TestCase;

/** D-054: the PHP and the TypeScript copy of the slide limits agree to the character. No database. */
class SlideLimitsTest extends TestCase
{
    /** @return array<string, int> "template.field" => target, from the TypeScript TARGETS */
    private function targets(): array
    {
        $ts = (string) file_get_contents(base_path('resources/js/slides/types.ts'));
        $this->assertSame(1, preg_match('/export const TARGETS = \{(.*?)\} as const;/s', $ts, $block), 'TARGETS found in types.ts');
        preg_match_all('/(\w+): \{([^}]*)\}/', $block[1], $groups, PREG_SET_ORDER);
        $out = [];
        foreach ($groups as [, $group, $fields]) {
            preg_match_all('/(\w+): (\d+)/', $fields, $pairs, PREG_SET_ORDER);
            foreach ($pairs as [, $field, $n]) {
                $out["$group.$field"] = (int) $n;
            }
        }

        return $out;
    }

    /** @return array<string, int> "template.field" => target, from SlideContract::templates() */
    private function phpTargets(): array
    {
        $out = ['deck.title' => SlideContract::TITLE_MAX, 'deck.minSlides' => SlideContract::MIN_SLIDES, 'deck.maxSlides' => SlideContract::MAX_SLIDES];
        $walk = function (string $path, array $rule) use (&$walk, &$out): void {
            if (isset($rule['max'])) {
                $out[$path] = $rule['max'];
            } elseif (isset($rule['list'])) {
                $walk($path, $rule['item']);
            } elseif (isset($rule['object'])) {
                foreach ($rule['object'] as $name => $sub) {
                    $walk("$path.$name", $sub);
                }
            }
        };
        foreach (SlideContract::templates() as $template => $fields) {
            foreach ($fields as $name => $rule) {
                $walk("$template.$name", $rule);
            }
        }

        return $out;
    }

    public function test_every_text_target_in_php_is_the_same_number_in_typescript(): void
    {
        $ts = $this->targets();
        // TS names a list's item "item", an object's fields by their own name; the PHP path ends at the field, so compare by the set of numbers per template.
        foreach (['title', 'section', 'bullets', 'two_columns', 'big_number', 'quote', 'steps'] as $template) {
            $php = array_values(array_unique(array_values(array_filter($this->phpTargets(), fn ($k) => str_starts_with($k, "$template."), ARRAY_FILTER_USE_KEY))));
            $theirs = array_values(array_unique(array_values(array_filter($ts, fn ($k) => str_starts_with($k, "$template.") && ! preg_match('/(min|max)(Items|Steps)$/', $k), ARRAY_FILTER_USE_KEY))));
            sort($php);
            sort($theirs);
            $this->assertSame($php, $theirs, "$template targets");
        }
        $this->assertSame(SlideContract::TITLE_MAX, $ts['deck.title']);
        $this->assertSame(SlideContract::MIN_SLIDES, $ts['deck.minSlides']);
        $this->assertSame(SlideContract::MAX_SLIDES, $ts['deck.maxSlides']);
    }

    public function test_the_hard_maximum_is_250_percent_in_whole_characters_and_the_typescript_says_the_same(): void
    {
        $this->assertSame(275, SlideContract::hardMax(110));
        $this->assertSame(30, SlideContract::hardMax(12));
        $this->assertSame(75, SlideContract::hardMax(30));
        $this->assertSame(550, SlideContract::hardMax(220));
        $this->assertSame(200, SlideContract::hardMax(80));

        $ts = (string) file_get_contents(base_path('resources/js/slides/types.ts'));
        $this->assertStringContainsString('Math.floor((target * 250) / 100)', $ts);
        $this->assertSame(250, SlideContract::HARD_PERCENT);
    }
}
