<?php

namespace Tests\Feature\Studio;

use App\Ai\Agents\RouterAgent;
use App\Models\Notebook;
use App\Models\Passage;
use App\Models\StudioItem;
use App\Models\UsageEvent;
use App\Models\User;
use App\Sources\EmbedSource;
use App\Studio\SlideContract;
use App\Studio\SlideTrim;
use Axyr\Langfuse\LangfuseFacade as Langfuse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;
use Tests\TestCase;

/** D-034: the slide deck. The contract is strict, over-length text is refused (never cut), one repair call, then a clean failure. Fakes only. */
class SlideDeckTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Notebook $notebook;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Langfuse::fake();
        Embeddings::fake();
        RouterAgent::fake(fn () => 'A summary.');
        $this->user = User::factory()->create();
        $this->notebook = Notebook::create(['user_id' => $this->user->id, 'title' => 'T']);
        $source = $this->notebook->sources()->create(['title' => 'Orion notes', 'status' => 'pending', 'content' => 'The Orion Project launched in March 2031.']);
        app()->call([new EmbedSource($source->id), 'handle']);
    }

    private function ref(): string
    {
        return 'P'.Passage::firstOrFail()->id;
    }

    /** A valid deck: eight slides, six different templates. */
    private function deck(): array
    {
        $c = [$this->ref()];
        $col = fn ($l) => ['label' => $l, 'items' => ['One', 'Two']];

        return ['title' => 'Orion', 'slides' => [
            ['template' => 'title', 'fields' => ['heading' => 'Orion', 'subheading' => 'A launch'], 'citations' => []],
            ['template' => 'section', 'fields' => ['heading' => 'The launch', 'kicker' => 'Part one'], 'citations' => []],
            ['template' => 'bullets', 'fields' => ['heading' => 'Facts', 'items' => ['Launched 2031', 'In March', 'A project']], 'citations' => $c],
            ['template' => 'big_number', 'fields' => ['heading' => 'When', 'number' => '2031', 'label' => 'launch year', 'context' => 'The project launched in March 2031.'], 'citations' => $c],
            ['template' => 'two_columns', 'fields' => ['heading' => 'Compare', 'left' => $col('Before'), 'right' => $col('After')], 'citations' => $c],
            ['template' => 'quote', 'fields' => ['quote' => 'The Orion Project launched in March 2031.', 'attribution' => 'Orion notes'], 'citations' => $c],
            ['template' => 'steps', 'fields' => ['heading' => 'How', 'steps' => [['label' => 'A', 'text' => 'First'], ['label' => 'B', 'text' => 'Second'], ['label' => 'C', 'text' => 'Third']]], 'citations' => $c],
            ['template' => 'bullets', 'fields' => ['heading' => 'Summary', 'items' => ['One', 'Two', 'Three']], 'citations' => $c],
        ]];
    }

    private function make(): StudioItem
    {
        return StudioItem::findOrFail($this->actingAs($this->user)->postJson("/notebooks/{$this->notebook->id}/studio", ['kind' => 'slides'])->assertStatus(202)->json('id'));
    }

    private function problems(array $deck): array
    {
        return SlideContract::validate($deck);
    }

    private function with(callable $change): array
    {
        $deck = $this->deck();
        $change($deck);

        return $deck;
    }

    // ---- the whole path ------------------------------------------------------------------------------------------

    public function test_a_valid_deck_is_stored_with_validated_citations_and_one_ledger_row(): void
    {
        $id = Passage::firstOrFail()->id;
        RouterAgent::fake(fn () => json_encode($this->deck()));

        $item = $this->make();

        $this->assertSame('done', $item->status);
        $this->assertSame('Orion', $item->title);
        $this->assertCount(8, $item->slides);
        $this->assertSame('title', $item->slides[0]['template']);
        $this->assertSame([], $item->slides[0]['citations']);
        $this->assertSame(['P'.$id], $item->slides[2]['citations'], 'strings like "P12", as the contract says');
        $this->assertSame($id, $item->citations[0]['id']);
        $this->assertSame(1, UsageEvent::where('prompt_name', 'studio.slides')->count());
        $this->assertSame(0, UsageEvent::where('prompt_name', 'studio.slides.repair')->count());

        $this->actingAs($this->user)->getJson("/notebooks/{$this->notebook->id}/studio/{$item->id}")->assertOk()
            ->assertJsonPath('kind', 'slides')->assertJsonPath('slides.4.template', 'two_columns')->assertJsonPath('slides.4.fields.right.items.1', 'Two');
    }

    /** Argus SD-1: what is stored and emitted must pass the same rules the viewer's checkDeck applies (a PHP mirror: SlideContract::validate). */
    public function test_the_stored_deck_passes_the_contract_the_viewer_checks_with_string_citations(): void
    {
        RouterAgent::fake(fn () => json_encode($this->deck()));

        $item = $this->make();
        $json = $this->actingAs($this->user)->getJson("/notebooks/{$this->notebook->id}/studio/{$item->id}")->assertOk()->json();

        $this->assertSame([], SlideContract::validate(['title' => $json['title'], 'slides' => $json['slides']]), 'what the server emits is a valid deck');
        foreach ($json['slides'] as $slide) {
            foreach ($slide['citations'] as $c) {
                $this->assertIsString($c);
                $this->assertMatchesRegularExpression('/^P\d+$/', $c);
            }
        }
        // And a deck with numeric citations is exactly what the contract refuses.
        $numeric = $json;
        $numeric['slides'][2]['citations'] = [Passage::firstOrFail()->id];
        $this->assertNotSame([], SlideContract::validate(['title' => $numeric['title'], 'slides' => $numeric['slides']]));
    }

    public function test_an_invented_citation_is_dropped_and_the_deck_still_stands(): void
    {
        $id = Passage::firstOrFail()->id;
        $deck = $this->with(function (&$d) use ($id) {
            $d['slides'][2]['citations'] = ["P$id", 'P999999'];
            $d['slides'][3]['citations'] = ['P999999'];
        });
        RouterAgent::fake(fn () => json_encode($deck));

        $item = $this->make();

        $this->assertSame('done', $item->status);
        $this->assertSame(['P'.$id], $item->slides[2]['citations']);
        $this->assertSame([], $item->slides[3]['citations']);
    }

    public function test_the_deck_may_come_wrapped_in_a_code_fence(): void
    {
        RouterAgent::fake(fn () => "Here is the deck:\n```json\n".json_encode($this->deck())."\n```");

        $this->assertSame('done', $this->make()->status);
    }

    // ---- repair --------------------------------------------------------------------------------------------------

    public function test_one_repair_call_gets_the_validators_errors_and_a_fixed_deck_is_accepted(): void
    {
        $bad = $this->with(fn (&$d) => $d['slides'][2]['fields']['heading'] = str_repeat('x', 151));
        $seen = [];
        $answers = [json_encode($bad), json_encode($this->deck())];
        RouterAgent::fake(function (string $prompt) use (&$seen, &$answers) {
            $seen[] = $prompt;

            return array_shift($answers);
        });

        $item = $this->make();

        $this->assertSame('done', $item->status);
        $this->assertCount(2, $seen);
        $this->assertStringContainsString('Slide 3 (bullets), "heading" is 151 characters; the limit is 150', $seen[1]);
        $this->assertSame(1, UsageEvent::where('prompt_name', 'studio.slides')->count());
        $this->assertSame(1, UsageEvent::where('prompt_name', 'studio.slides.repair')->count());
        $this->assertSame('Facts', $item->slides[2]['fields']['heading'], 'the repaired deck is stored as returned, never cut by the server');
    }

    public function test_text_that_is_not_json_is_repaired_the_same_way(): void
    {
        $answers = ['Sorry, here is a deck in words.', json_encode($this->deck())];
        RouterAgent::fake(function () use (&$answers) {
            return array_shift($answers);
        });

        $this->assertSame('done', $this->make()->status);
    }

    public function test_a_second_failure_fails_cleanly_with_both_ledger_rows_and_nothing_stored(): void
    {
        $bad = $this->with(fn (&$d) => $d['slides'][2]['fields']['heading'] = str_repeat('x', 151));
        RouterAgent::fake(fn () => json_encode($bad));

        $item = $this->make();

        $this->assertSame('failed', $item->status);
        $this->assertSame('The model did not return a usable slide deck.', $item->error);
        $this->assertNull($item->slides);
        $this->assertSame(1, UsageEvent::where('prompt_name', 'studio.slides')->count());
        $this->assertSame(1, UsageEvent::where('prompt_name', 'studio.slides.repair')->count());
    }

    public function test_the_cap_is_refused_before_any_call_is_wasted(): void
    {
        config(['ledger.user_caps.free' => 500_000]); // a small cap of this test's own, whatever the default
        UsageEvent::create(['user_id' => $this->user->id, 'provider' => 'x', 'model' => 'x', 'kind' => 'chat', 'units' => [], 'cost_micro_usd' => 500_000]);
        RouterAgent::fake(fn () => json_encode($this->deck()));

        $item = $this->make();

        $this->assertSame('failed', $item->status);
        $this->assertStringContainsString('limit', (string) $item->error);
        $this->assertSame(0, UsageEvent::where('prompt_name', 'studio.slides')->count());
    }

    // ---- the contract: every kind of invalid deck -----------------------------------------------------------------

    public function test_the_valid_deck_and_the_deck_at_every_maximum_pass(): void
    {
        $this->assertSame([], $this->problems($this->deck()));

        $deck = $this->deck();
        $deck['title'] = str_repeat('t', 80);
        $deck['slides'][0]['fields'] = ['heading' => str_repeat('h', 70), 'subheading' => str_repeat('s', 120)];
        $deck['slides'][1]['fields'] = ['heading' => str_repeat('h', 60), 'kicker' => str_repeat('k', 40)];
        $deck['slides'][2]['fields'] = ['heading' => str_repeat('h', 60), 'items' => array_fill(0, 5, str_repeat('i', 110))];
        $deck['slides'][3]['fields'] = ['heading' => str_repeat('h', 60), 'number' => str_repeat('9', 12), 'label' => str_repeat('l', 60), 'context' => str_repeat('c', 160)];
        $col = ['label' => str_repeat('l', 30), 'items' => array_fill(0, 4, str_repeat('i', 80))];
        $deck['slides'][4]['fields'] = ['heading' => str_repeat('h', 60), 'left' => $col, 'right' => $col];
        $deck['slides'][5]['fields'] = ['quote' => str_repeat('q', 220), 'attribution' => str_repeat('a', 80)];
        $deck['slides'][6]['fields'] = ['heading' => str_repeat('h', 60), 'steps' => array_fill(0, 5, ['label' => str_repeat('l', 30), 'text' => str_repeat('t', 90)])];
        $this->assertSame([], $this->problems($deck));
    }

    public function test_every_text_field_one_over_its_limit_is_refused_and_never_cut(): void
    {
        $over = fn (array $path, int $limit) => $this->with(function (&$d) use ($path, $limit) {
            $ref = &$d;
            foreach ($path as $key) {
                $ref = &$ref[$key];
            }
            $ref = str_repeat('x', $limit + 1);
        });

        $cases = [
            [['title'], 80, 'deck title'],
            [['slides', 0, 'fields', 'heading'], 70, 'Slide 1'], [['slides', 0, 'fields', 'subheading'], 120, 'Slide 1'],
            [['slides', 1, 'fields', 'heading'], 60, 'Slide 2'], [['slides', 1, 'fields', 'kicker'], 40, 'Slide 2'],
            [['slides', 2, 'fields', 'heading'], 60, 'Slide 3'], [['slides', 2, 'fields', 'items', 0], 110, 'Slide 3'],
            [['slides', 3, 'fields', 'heading'], 60, 'Slide 4'], [['slides', 3, 'fields', 'number'], 12, 'Slide 4'],
            [['slides', 3, 'fields', 'label'], 60, 'Slide 4'], [['slides', 3, 'fields', 'context'], 160, 'Slide 4'],
            [['slides', 4, 'fields', 'heading'], 60, 'Slide 5'], [['slides', 4, 'fields', 'left', 'label'], 30, 'Slide 5'],
            [['slides', 4, 'fields', 'left', 'items', 1], 80, 'Slide 5'], [['slides', 4, 'fields', 'right', 'label'], 30, 'Slide 5'],
            [['slides', 4, 'fields', 'right', 'items', 0], 80, 'Slide 5'],
            [['slides', 5, 'fields', 'quote'], 220, 'Slide 6'], [['slides', 5, 'fields', 'attribution'], 80, 'Slide 6'],
            [['slides', 6, 'fields', 'heading'], 60, 'Slide 7'], [['slides', 6, 'fields', 'steps', 0, 'label'], 30, 'Slide 7'],
            [['slides', 6, 'fields', 'steps', 2, 'text'], 90, 'Slide 7'],
        ];
        foreach ($cases as [$path, $target, $where]) {
            $hard = SlideContract::hardMax($target); // D-054: the cases name the TARGETS; a text is refused only beyond 250% of it, and accepted AT it
            $errors = $this->problems($over($path, $hard));
            $this->assertCount(1, $errors, implode('/', $path));
            $this->assertStringContainsString($where, $errors[0]);
            $this->assertStringContainsString('the limit is '.$hard, $errors[0]);
            $this->assertSame([], $this->problems($over($path, $hard - 1)), implode('/', $path).' at the hard maximum');
        }
    }

    public function test_the_shape_of_the_deck_is_checked(): void
    {
        $this->assertNotSame([], $this->problems([]), 'no title, no slides');
        $this->assertSame(['The answer is not a JSON object with a title and slides.'], SlideContract::validate('x'));
        $this->assertSame(['The answer is not a JSON object with a title and slides.'], SlideContract::validate(null));
        $this->assertContains('The deck needs a title (text).', $this->problems($this->with(fn (&$d) => $d['title'] = '')));
        $this->assertContains('The deck needs a title (text).', $this->problems($this->with(function (&$d) {
            unset($d['title']);
        })));
        $this->assertNotSame([], $this->problems($this->with(fn (&$d) => $d['title'] = '<b>Orion</b>')));
        $this->assertSame(['"slides" must be a list of slides.'], $this->problems(['title' => 'T', 'slides' => 'no']));
        $this->assertSame(['"slides" must be a list of slides.'], $this->problems(['title' => 'T', 'slides' => ['a' => []]]));

        $this->assertStringContainsString('has 5 slides', $this->problems($this->with(fn (&$d) => $d['slides'] = array_slice($d['slides'], 0, 5)))[0]);
        $this->assertStringContainsString('has 13 slides', implode(' ', $this->problems($this->with(fn (&$d) => $d['slides'] = array_merge($d['slides'], array_slice($d['slides'], 2, 5))))));
        $this->assertSame([], $this->problems($this->with(fn (&$d) => $d['slides'] = array_slice($d['slides'], 0, 6))), 'six is allowed');
    }

    public function test_the_first_slide_is_a_title_and_a_section_is_never_last(): void
    {
        $notTitleFirst = $this->with(function (&$d) {
            [$d['slides'][0], $d['slides'][1]] = [$d['slides'][1], $d['slides'][0]];
        });
        $this->assertContains('The first slide must use the "title" template.', $this->problems($notTitleFirst));

        $sectionLast = $this->with(fn (&$d) => $d['slides'][7] = ['template' => 'section', 'fields' => ['heading' => 'End', 'kicker' => 'Fin'], 'citations' => []]);
        $this->assertContains('The last slide must not be a "section" slide.', $this->problems($sectionLast));
    }

    public function test_unknown_templates_missing_extra_and_wrongly_shaped_fields_are_refused(): void
    {
        $unknown = $this->with(fn (&$d) => $d['slides'][2]['template'] = 'carousel');
        $this->assertStringContainsString('Slide 3 has an unknown template', implode(' ', $this->problems($unknown)));
        $this->assertStringContainsString('unknown template', implode(' ', $this->problems($this->with(fn (&$d) => $d['slides'][2]['template'] = ['bullets']))));
        $this->assertStringContainsString('Slide 3 is not an object', implode(' ', $this->problems($this->with(fn (&$d) => $d['slides'][2] = 'bullets'))));

        $noFields = $this->with(function (&$d) {
            unset($d['slides'][2]['fields']);
        });
        $this->assertStringContainsString('Slide 3 (bullets) has no "fields" object', implode(' ', $this->problems($noFields)));

        $missing = $this->with(function (&$d) {
            unset($d['slides'][3]['fields']['context']);
        });
        $this->assertStringContainsString('Slide 4 (big_number), "context" is missing or empty', implode(' ', $this->problems($missing)));
        $this->assertStringContainsString('missing or empty', implode(' ', $this->problems($this->with(fn (&$d) => $d['slides'][3]['fields']['label'] = '   '))));
        $this->assertStringContainsString('missing or empty', implode(' ', $this->problems($this->with(fn (&$d) => $d['slides'][3]['fields']['label'] = 12))));

        $extra = $this->with(fn (&$d) => $d['slides'][2]['fields']['html'] = '<div>');
        $this->assertStringContainsString('has a field "html" that this template does not have', implode(' ', $this->problems($extra)));

        // A field of another template (a bullet slide with a quote) is both missing and extra.
        $wrong = $this->with(fn (&$d) => $d['slides'][2]['fields'] = ['quote' => 'x', 'attribution' => 'y']);
        $this->assertCount(4, $this->problems($wrong));
    }

    public function test_lists_must_have_the_right_number_of_items_of_the_right_kind(): void
    {
        $items = fn (array $list) => $this->with(fn (&$d) => $d['slides'][2]['fields']['items'] = $list);
        $this->assertStringContainsString('has 2 items; it must have 3 to 5', implode(' ', $this->problems($items(['a', 'b']))));
        $this->assertStringContainsString('has 6 items; it must have 3 to 5', implode(' ', $this->problems($items(array_fill(0, 6, 'a')))));
        $this->assertStringContainsString('must be a list', implode(' ', $this->problems($items(['a' => 'x', 'b' => 'y', 'c' => 'z']))));
        $this->assertStringContainsString('must be a list', implode(' ', $this->problems($this->with(fn (&$d) => $d['slides'][2]['fields']['items'] = 'a, b, c'))));
        $this->assertStringContainsString('item 2 is missing or empty', implode(' ', $this->problems($items(['a', '', 'c']))));

        $left = fn (array $column) => $this->with(fn (&$d) => $d['slides'][4]['fields']['left'] = $column);
        $this->assertStringContainsString('has 1 items; it must have 2 to 4', implode(' ', $this->problems($left(['label' => 'L', 'items' => ['one']]))));
        $this->assertStringContainsString('has 5 items; it must have 2 to 4', implode(' ', $this->problems($left(['label' => 'L', 'items' => array_fill(0, 5, 'a')]))));
        $this->assertStringContainsString('"left" must be an object', implode(' ', $this->problems($left(['a', 'b']))));
        $this->assertStringContainsString('"left" must be an object', implode(' ', $this->problems($this->with(fn (&$d) => $d['slides'][4]['fields']['left'] = 'text'))));

        $steps = fn (array $list) => $this->with(fn (&$d) => $d['slides'][6]['fields']['steps'] = $list);
        $this->assertStringContainsString('has 2 items; it must have 3 to 5', implode(' ', $this->problems($steps([['label' => 'a', 'text' => 'b'], ['label' => 'a', 'text' => 'b']]))));
        $this->assertStringContainsString('must be an object', implode(' ', $this->problems($steps(['a', 'b', 'c']))));
        $this->assertStringContainsString('"text" is missing or empty', implode(' ', $this->problems($steps([['label' => 'a'], ['label' => 'a', 'text' => 'b'], ['label' => 'a', 'text' => 'b']]))));
    }

    public function test_markup_in_a_field_is_refused(): void
    {
        $this->assertStringContainsString('plain text, with no markup', implode(' ', $this->problems($this->with(fn (&$d) => $d['slides'][2]['fields']['heading'] = 'Facts <script>alert(1)</script>'))));
        $this->assertStringContainsString('plain text, with no markup', implode(' ', $this->problems($this->with(fn (&$d) => $d['slides'][2]['fields']['items'][0] = '<b>bold</b>'))));
    }

    public function test_citations_must_be_a_list_of_passage_ids(): void
    {
        foreach (['P1', 12, ['P1', 7], [['P1']], 'P1, P2', ['p1'], ['P']] as $bad) {
            $errors = $this->problems($this->with(fn (&$d) => $d['slides'][2]['citations'] = $bad));
            $this->assertSame(['Slide 3: "citations" must be a list of passage ids like "P12".'], $errors, json_encode($bad));
        }
        $this->assertSame([], $this->problems($this->with(function (&$d) {
            unset($d['slides'][2]['citations']);
        })), 'no citations at all is allowed: the chat rule only drops what is not real');
    }

    public function test_variety_no_more_than_two_in_a_row_and_at_least_four_templates(): void
    {
        $three = $this->with(function (&$d) {
            $d['slides'][3] = $d['slides'][2];
            $d['slides'][4] = $d['slides'][2];
        });
        $this->assertStringContainsString('Slide 5 repeats the "bullets" template more than 2 times in a row', implode(' ', $this->problems($three)));

        $two = $this->with(fn (&$d) => $d['slides'][3] = $d['slides'][2]);
        $this->assertSame([], $this->problems($two), 'two in a row is allowed');

        $few = $this->with(function (&$d) {
            $d['slides'] = [$d['slides'][0], $d['slides'][2], $d['slides'][2], $d['slides'][1], $d['slides'][2], $d['slides'][2], $d['slides'][1], $d['slides'][2]];
        });
        $this->assertContains('The deck must use at least 4 different templates.', $this->problems($few));
    }

    public function test_the_contract_names_exactly_the_seven_templates(): void
    {
        $this->assertSame(['title', 'section', 'bullets', 'two_columns', 'big_number', 'quote', 'steps'], SlideContract::names());
    }

    // ---- counts are structure: trimmed before the check (a Mistral-shaped failure) ---------------------------------------

    public function test_counts_are_trimmed_deterministically_and_lengths_are_never_touched(): void
    {
        $deck = $this->deck();
        $deck['slides'][2]['fields']['items'] = array_map(fn ($i) => "Bullet $i", range(1, 7));
        $deck['slides'][4]['fields']['left']['items'] = array_map(fn ($i) => "L$i", range(1, 6));
        $deck['slides'][6]['fields']['steps'] = array_map(fn ($i) => ['label' => "S$i", 'text' => "Step $i"], range(1, 7));
        $long = str_repeat('x', 300);
        $deck['slides'][5]['fields']['quote'] = $long;

        [$out, $trimmed] = SlideTrim::apply($deck);

        $this->assertSame(['Bullet 1', 'Bullet 2', 'Bullet 3', 'Bullet 4', 'Bullet 5'], $out['slides'][2]['fields']['items']);
        $this->assertSame(['L1', 'L2', 'L3', 'L4'], $out['slides'][4]['fields']['left']['items']);
        $this->assertCount(5, $out['slides'][6]['fields']['steps']);
        $this->assertSame($long, $out['slides'][5]['fields']['quote'], 'text is never cut');
        $this->assertSame(['slides' => 0, 'bullets' => 2, 'column_items' => 2, 'steps' => 2], $trimmed);
        $this->assertSame('trimmed: 2 bullets, 2 column items, 2 steps', SlideTrim::note($trimmed));
        $this->assertNull(SlideTrim::note(['slides' => 0, 'bullets' => 0, 'column_items' => 0, 'steps' => 0]));
    }

    public function test_too_many_slides_keep_the_first_ones_and_the_closing_slide(): void
    {
        $deck = $this->deck();
        $extra = array_map(fn ($i) => ['template' => $i % 2 ? 'quote' : 'big_number', 'fields' => $i % 2
            ? ['quote' => "Quote $i", 'attribution' => 'A'] : ['heading' => "H$i", 'number' => '1', 'label' => 'l', 'context' => 'c'], 'citations' => []], range(1, 7));
        $deck['slides'] = array_merge($deck['slides'], $extra); // 15 slides; the last is a quote
        $closing = $deck['slides'][14];

        [$out, $trimmed] = SlideTrim::apply($deck);

        $this->assertCount(12, $out['slides']);
        $this->assertSame($closing, $out['slides'][11], 'the closing slide stays last');
        $this->assertSame($deck['slides'][10], $out['slides'][10]);
        $this->assertSame(3, $trimmed['slides']);

        // A closing section is not kept as the closing slide: the last real slide takes its place.
        $deck['slides'][14] = ['template' => 'section', 'fields' => ['heading' => 'End', 'kicker' => 'Fin'], 'citations' => []];
        [$out] = SlideTrim::apply($deck);
        $this->assertSame($deck['slides'][13], $out['slides'][11]);

        // A deck that fits is left as it is; so is something that is not a deck.
        $this->assertSame([$this->deck(), 0], [SlideTrim::apply($this->deck())[0], array_sum(SlideTrim::apply($this->deck())[1])]);
        $this->assertSame('x', SlideTrim::apply('x')[0]);
        $this->assertSame(['title' => 'T', 'slides' => 'no'], SlideTrim::apply(['title' => 'T', 'slides' => 'no'])[0]);
    }

    /** A failure recorded from Mistral Small on dev: 14 slides, 7 bullets, a 268-character quote. */
    public function test_a_mistral_shaped_deck_is_trimmed_then_repaired_on_the_one_over_length_field_only(): void
    {
        $deck = $this->deck();
        $deck['slides'][2]['fields']['items'] = array_map(fn ($i) => "Bullet $i", range(1, 7));
        $deck['slides'][5]['fields']['quote'] = str_repeat('q', 551);
        $deck['slides'] = array_merge($deck['slides'], [
            ['template' => 'big_number', 'fields' => ['heading' => 'H', 'number' => '9', 'label' => 'l', 'context' => 'c'], 'citations' => []],
            ['template' => 'steps', 'fields' => ['heading' => 'S', 'steps' => [['label' => 'a', 'text' => 'b'], ['label' => 'a', 'text' => 'b'], ['label' => 'a', 'text' => 'b']]], 'citations' => []],
            ['template' => 'two_columns', 'fields' => ['heading' => 'C', 'left' => ['label' => 'l', 'items' => ['a', 'b']], 'right' => ['label' => 'r', 'items' => ['a', 'b']]], 'citations' => []],
            ['template' => 'big_number', 'fields' => ['heading' => 'H', 'number' => '9', 'label' => 'l', 'context' => 'c'], 'citations' => []],
            ['template' => 'steps', 'fields' => ['heading' => 'S', 'steps' => [['label' => 'a', 'text' => 'b'], ['label' => 'a', 'text' => 'b'], ['label' => 'a', 'text' => 'b']]], 'citations' => []],
            ['template' => 'bullets', 'fields' => ['heading' => 'End', 'items' => ['a', 'b', 'c']], 'citations' => []],
        ]);
        $this->assertSame(14, count($deck['slides']));

        $fixed = $this->deck();
        $fixed['slides'][2]['fields']['items'] = array_map(fn ($i) => "Bullet $i", range(1, 5));
        $fixed['slides'] = array_merge($deck['slides'], []); // same 14, only the quote shortened below
        $fixed['slides'][5]['fields']['quote'] = 'A short quote.';
        $fixed['slides'][2]['fields']['items'] = array_map(fn ($i) => "Bullet $i", range(1, 7));

        $seen = [];
        $answers = [json_encode($deck), json_encode($fixed)];
        RouterAgent::fake(function (string $prompt) use (&$seen, &$answers) {
            $seen[] = $prompt;

            return array_shift($answers);
        });

        $item = $this->make();

        $this->assertSame('done', $item->status);
        $this->assertCount(2, $seen, 'one first call and one repair call');
        $this->assertStringContainsString('Slide 6 (quote), "quote" is 551 characters; the limit is 550', $seen[1]);
        $this->assertStringNotContainsString('items" has 7 items', $seen[1], 'counts were trimmed, not sent back to the model');
        $this->assertStringNotContainsString('has 14 slides', $seen[1]);
        $this->assertCount(12, $item->slides);
        $this->assertSame(5, count($item->slides[2]['fields']['items']));
        $this->assertSame('trimmed: 2 slides, 2 bullets', $item->settings['trimmed']);
        $this->assertSame([], SlideContract::validate(['title' => $item->title, 'slides' => $item->slides]));
    }

    public function test_when_the_deck_has_another_number_of_slides_than_asked_the_settings_record_it(): void
    {
        RouterAgent::fake(fn () => json_encode($this->deck())); // eight slides

        $asked10 = StudioItem::findOrFail($this->actingAs($this->user)->postJson("/notebooks/{$this->notebook->id}/studio", ['kind' => 'slides', 'options' => ['slides' => 10]])->json('id'));
        $this->assertSame(['asked' => 10, 'got' => 8], $asked10->settings['delivered']);

        $asked8 = $this->make();
        $this->assertArrayNotHasKey('delivered', $asked8->settings, 'eight is the default: as asked');
    }

    /** HC-7 (Hipparchus): "Make it again" must not copy the old run's trim note onto a deck that was not trimmed. */
    public function test_make_it_again_does_not_carry_over_the_old_trim_note(): void
    {
        $long = $this->deck();
        $long['slides'][2]['fields']['items'] = array_map(fn ($i) => "Bullet $i", range(1, 7));
        RouterAgent::fake(fn () => json_encode($long));
        $first = $this->make();
        $this->assertSame('trimmed: 2 bullets', $first->settings['trimmed']);

        RouterAgent::fake(fn () => json_encode($this->deck()));
        $again = StudioItem::findOrFail($this->actingAs($this->user)->postJson("/notebooks/{$this->notebook->id}/studio/{$first->id}/regenerate")->assertStatus(202)->json('id'));

        $this->assertSame('done', $again->status);
        $this->assertArrayNotHasKey('trimmed', $again->settings);
        $this->assertSame($first->id, $again->settings['regenerated_from']);
    }

    /** HC-4 (Hipparchus), the SlideTrim part: the trim reads its maxima from the contract, so a change there is followed, not contradicted. */
    public function test_the_trim_follows_the_contracts_maxima(): void
    {
        $spec = SlideContract::templates();
        $deck = $this->deck();
        $deck['slides'][2]['fields']['items'] = array_map(fn ($i) => "B$i", range(1, 9));
        $deck['slides'][4]['fields']['left']['items'] = array_map(fn ($i) => "L$i", range(1, 9));
        $deck['slides'][6]['fields']['steps'] = array_map(fn ($i) => ['label' => "S$i", 'text' => "T$i"], range(1, 9));

        [$out] = SlideTrim::apply($deck);

        $this->assertCount($spec['bullets']['items']['list'][1], $out['slides'][2]['fields']['items']);
        $this->assertCount($spec['two_columns']['left']['object']['items']['list'][1], $out['slides'][4]['fields']['left']['items']);
        $this->assertCount($spec['steps']['steps']['list'][1], $out['slides'][6]['fields']['steps']);
        // What the trim leaves is what the contract accepts for counts: no 'it must have' complaint remains.
        $this->assertSame([], SlideContract::validate($out));
    }
}
