<?php

namespace Tests\Feature\Studio;

use App\Studio\InfographicBrief;
use Tests\TestCase;

/** D-054: the prompt states targets, the checker refuses only beyond generous hard maximums, and never cuts. No database. */
class InfographicBriefTest extends TestCase
{
    private function brief(array $change = []): string
    {
        $points = array_map(fn ($i) => ['heading' => "Fact $i", 'figure' => (string) $i, 'text' => "Fact number $i.", 'refs' => ['P1']], range(1, 5));

        return json_encode(array_replace_recursive(['title' => 'Title', 'points' => $points], $change));
    }

    /** @return list<string> the problems with one field at a length */
    private function problems(string $field, int $length): array
    {
        $change = $field === 'title' ? ['title' => str_repeat('t', $length)] : ['points' => [0 => [$field => str_repeat('t', $length)]]];

        return InfographicBrief::check($this->brief($change), [1], 5)[1];
    }

    public function test_the_prod_cases_and_a_long_text_are_kept_as_written_and_one_over_the_hard_maximum_is_refused(): void
    {
        foreach ([128, 299, 300] as $length) {
            $text = str_repeat('a', $length - 1).'.';
            [$kept, $problems] = InfographicBrief::check($this->brief(['points' => [3 => ['text' => $text]]]), [1], 5);

            $this->assertSame([], $problems, "text of $length");
            $this->assertSame($text, $kept['points'][3]['text'], 'not cut');
        }

        [$none, $problems] = InfographicBrief::check($this->brief(['points' => [3 => ['text' => str_repeat('a', 301)]]]), [1], 5);

        $this->assertNull($none);
        $this->assertSame(['Point 4 text is over text_hard_max (300 characters): make it shorter.'], $problems);
    }

    public function test_every_field_passes_at_its_hard_maximum_and_is_refused_one_over(): void
    {
        foreach (['title' => 150, 'heading' => 80, 'figure' => 40, 'text' => 300] as $field => $hard) {
            $this->assertSame([], $this->problems($field, $hard), "$field at $hard");
            $this->assertNotSame([], $this->problems($field, $hard + 1), "$field at ".($hard + 1));
        }
    }

    public function test_the_targets_are_not_refused_and_the_prompt_still_states_them(): void
    {
        foreach (['title' => 80, 'heading' => 40, 'figure' => 16, 'text' => 120] as $field => $target) {
            $this->assertSame($target, config("infographics.{$field}_max"));
            $this->assertSame([], $this->problems($field, $target + 1), "$field just over its target");
        }
    }

    public function test_a_missing_hard_maximum_falls_back_to_two_and_a_half_times_the_target_never_to_refusing_everything(): void
    {
        config(['infographics.text_hard_max' => null, 'infographics.title_hard_max' => null]);

        $this->assertSame([], $this->problems('text', 300), '120 x 2.5');
        $this->assertNotSame([], $this->problems('text', 301));
        $this->assertSame([], $this->problems('title', 200), '80 x 2.5');
        $this->assertSame([], $this->problems('heading', 5), 'an ordinary brief still passes');
    }
}
