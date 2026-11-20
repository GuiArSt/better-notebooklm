<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** scripts/demo/fix-mojibake.php: repair exact Windows-1250 mojibake of German letters in an owner's Studio items and notes, and nothing else. */
class FixMojibakeTest extends TestCase
{
    use RefreshDatabase;

    private int $owner;

    private int $reviewer;

    private int $ownerNotebook;

    private int $reviewerNotebook;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner'])->id;
        $this->reviewer = User::factory()->create(['role' => 'reviewer'])->id;
        $this->ownerNotebook = $this->notebook($this->owner, 'Wasserstelle');
        $this->reviewerNotebook = $this->notebook($this->reviewer, 'Fremd');
    }

    /** What the bug does to a German letter: its UTF-8 bytes read as Windows-1250. */
    private function garble(string $text): string
    {
        return (string) iconv('WINDOWS-1250', 'UTF-8', $text);
    }

    private function notebook(int $user, string $title): int
    {
        return DB::table('notebooks')->insertGetId(['user_id' => $user, 'title' => $title, 'emoji' => '📘', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function item(int $notebook, array $columns): int
    {
        return DB::table('studio_items')->insertGetId($columns + ['notebook_id' => $notebook, 'user_id' => DB::table('notebooks')->where('id', $notebook)->value('user_id'), 'kind' => 'briefing', 'title' => 'T', 'status' => 'done', 'step_n' => 0, 'step_total' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return string the script's output */
    private function fix(array $env = []): string
    {
        foreach ($env as $k => $v) {
            putenv("$k=$v");
        }
        ob_start();
        try {
            include base_path('../scripts/demo/fix-mojibake.php');
        } finally {
            $out = (string) ob_get_clean();
            foreach (array_keys($env) as $k) {
                putenv($k);
            }
        }

        return $out;
    }

    public function test_the_table_is_what_encoding_each_letter_gives(): void
    {
        $expected = ['ä' => "\u{0102}\u{00A4}", 'ö' => "\u{0102}\u{00B6}", 'ü' => "\u{0102}\u{013D}", 'ß' => "\u{0102}\u{017A}", 'Ä' => "\u{0102}\u{201E}", 'Ö' => "\u{0102}\u{2013}", 'Ü' => "\u{0102}\u{015B}"];
        foreach ($expected as $letter => $pair) {
            $this->assertSame($pair, $this->garble($letter), "the mojibake of $letter");
        }

        // And the script repairs every one of them, in a plain text column.
        $id = $this->item($this->ownerNotebook, ['content' => implode(' ', array_map(fn ($l) => 'x'.$this->garble($l).'y', array_keys($expected)))]);
        $this->fix();

        $this->assertSame(implode(' ', array_map(fn ($l) => "x{$l}y", array_keys($expected))), DB::table('studio_items')->where('id', $id)->value('content'));
    }

    public function test_it_repairs_text_json_raw_and_escaped_in_items_and_notes_and_keeps_the_json_valid_and_in_its_own_style(): void
    {
        $quiz = json_encode([['question' => 'F'.$this->garble('ü').'r wen?', 'options' => ['a', 'b', 'c', 'd'], 'answer' => 0]]);                       // escaped, as Laravel writes it
        $slides = json_encode([['template' => 'title', 'fields' => ['heading' => 'Gr'.$this->garble('ö').$this->garble('ß').'e']]], JSON_UNESCAPED_UNICODE);  // raw
        $item = $this->item($this->ownerNotebook, [
            'title' => 'K'.$this->garble('ü').'rzer', 'content' => 'F'.$this->garble('ä').'higkeiten [P12]', 'quiz' => $quiz, 'slides' => $slides,
            'tree' => json_encode(['title' => $this->garble('Ö').'ffentlich', 'children' => []]),
            'settings' => json_encode(['kind' => 'quiz', 'sources' => [['id' => 1, 'title' => 'W'.$this->garble('ü').'rde']], 'cost_micro_usd' => 5]),
            'citations' => json_encode([['id' => 1, 'quote' => 'f'.$this->garble('ü').'r', 'source_id' => 2]]),
        ]);
        $note = DB::table('notes')->insertGetId(['notebook_id' => $this->ownerNotebook, 'title' => 'N'.$this->garble('ü').'tzlich', 'content' => 'Sch'.$this->garble('ö').'n', 'citations' => json_encode([['quote' => 'm'.$this->garble('ä').'chtig']]), 'from_chat' => true, 'checked' => true, 'created_at' => now(), 'updated_at' => now()]);

        $out = $this->fix();

        $row = DB::table('studio_items')->where('id', $item)->first();
        $this->assertSame('Kürzer', $row->title);
        $this->assertSame('Fähigkeiten [P12]', $row->content, 'nothing else in the text moved');
        $this->assertSame('Für wen?', json_decode($row->quiz, true)[0]['question']);
        $this->assertStringContainsString('\\u00fc', $row->quiz, 'an escaped pair is repaired as an escape');
        $this->assertSame('Größe', json_decode($row->slides, true)[0]['fields']['heading']);
        $this->assertStringContainsString('Größe', $row->slides, 'a raw pair is repaired raw');
        $this->assertSame('Öffentlich', json_decode($row->tree, true)['title']);
        $this->assertSame('Würde', json_decode($row->settings, true)['sources'][0]['title']);
        $this->assertSame(5, json_decode($row->settings, true)['cost_micro_usd']);
        $this->assertSame('für', json_decode($row->citations, true)[0]['quote']);
        $n = DB::table('notes')->where('id', $note)->first();
        $this->assertSame(['Nützlich', 'Schön', 'mächtig'], [$n->title, $n->content, json_decode($n->citations, true)[0]['quote']]);
        $this->assertStringContainsString("studio item ids: $item", $out);
        $this->assertStringContainsString("note ids: $note", $out);
    }

    public function test_a_rehearsal_writes_nothing_prints_ids_and_counts_and_never_a_word_of_text(): void
    {
        $item = $this->item($this->ownerNotebook, ['content' => 'F'.$this->garble('ü').'r Gr'.$this->garble('ö').$this->garble('ß').'e']);
        $before = DB::table('studio_items')->where('id', $item)->value('content');

        $out = $this->fix(['DRY' => '1']);

        $this->assertSame($before, DB::table('studio_items')->where('id', $item)->value('content'), 'nothing written');
        $this->assertStringContainsString('DRY RUN (nothing was written). Would repair: 1 studio item(s)', $out);
        $this->assertStringContainsString("studio item ids: $item", $out);
        $this->assertStringContainsString('replacements by column: studio_items.content 3', $out);
        foreach (['Wasserstelle', 'Gr', 'F'.$this->garble('ü'), 'für', 'Größe'] as $word) {
            $this->assertStringNotContainsString($word, $out);
        }
    }

    public function test_it_changes_nothing_that_is_not_an_exact_pair(): void
    {
        $legit = $this->item($this->ownerNotebook, ['content' => 'Schöne Grüße: ä ö ü ß Ä Ö Ü, déjà vu, Zażółć gęślą jaźń', 'title' => 'ĂX Ă alone', 'settings' => json_encode(['note' => 'Zażółć'])]);
        $before = (array) DB::table('studio_items')->where('id', $legit)->first();

        $out = $this->fix();

        $this->assertSame($before, (array) DB::table('studio_items')->where('id', $legit)->first(), 'a row with no pair is not touched, not even its updated_at');
        $this->assertStringContainsString('studio item ids: (none)', $out);
    }

    public function test_it_is_idempotent_a_second_run_changes_nothing(): void
    {
        $this->item($this->ownerNotebook, ['content' => 'F'.$this->garble('ü').'r']);
        $this->fix();
        $after = DB::table('studio_items')->pluck('content')->all();

        $out = $this->fix();

        $this->assertSame($after, DB::table('studio_items')->pluck('content')->all());
        $this->assertStringContainsString('Repaired: 0 studio item(s), 0 note(s)', $out);
    }

    public function test_only_notebooks_owned_by_an_owner_are_touched_and_a_named_one_that_is_not_is_refused(): void
    {
        $theirs = $this->item($this->reviewerNotebook, ['content' => 'F'.$this->garble('ü').'r']);
        $mine = $this->item($this->ownerNotebook, ['content' => 'F'.$this->garble('ü').'r']);
        $garbled = 'F'.$this->garble('ü').'r';

        $out = $this->fix();
        $this->assertSame($garbled, DB::table('studio_items')->where('id', $theirs)->value('content'), 'a reviewer\'s notebook is never touched');
        $this->assertSame('Für', DB::table('studio_items')->where('id', $mine)->value('content'));

        DB::table('studio_items')->where('id', $mine)->update(['content' => $garbled]);
        $named = $this->fix(['NOTEBOOK_IDS' => $this->reviewerNotebook.','.$this->ownerNotebook]);
        $this->assertStringContainsString("REFUSED: notebook {$this->reviewerNotebook} does not exist or does not belong to an owner", $named);
        $this->assertSame($garbled, DB::table('studio_items')->where('id', $theirs)->value('content'));
        $this->assertSame('Für', DB::table('studio_items')->where('id', $mine)->value('content'));
        $this->assertStringContainsString('not touched', $named);
    }

    public function test_notebook_ids_restricts_the_run_to_those_notebooks(): void
    {
        $other = $this->notebook($this->owner, 'Zweites');
        $a = $this->item($this->ownerNotebook, ['content' => 'F'.$this->garble('ü').'r']);
        $b = $this->item($other, ['content' => 'F'.$this->garble('ü').'r']);

        $this->fix(['NOTEBOOK_IDS' => (string) $other]);

        $this->assertSame('F'.$this->garble('ü').'r', DB::table('studio_items')->where('id', $a)->value('content'));
        $this->assertSame('Für', DB::table('studio_items')->where('id', $b)->value('content'));
    }

    public function test_sources_and_passages_are_counted_but_never_changed(): void
    {
        $source = DB::table('sources')->insertGetId(['notebook_id' => $this->ownerNotebook, 'kind' => 'text', 'title' => 'Q', 'content' => 'F'.$this->garble('ü').'r', 'status' => 'ready', 'checked' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('passages')->insert(['notebook_id' => $this->ownerNotebook, 'source_id' => $source, 'position' => 0, 'start_offset' => 0, 'end_offset' => 3, 'content' => 'F'.$this->garble('ü').'r', 'created_at' => now(), 'updated_at' => now()]);

        $out = $this->fix();

        $this->assertSame('F'.$this->garble('ü').'r', DB::table('sources')->where('id', $source)->value('content'));
        $this->assertSame('F'.$this->garble('ü').'r', DB::table('passages')->where('source_id', $source)->value('content'));
        $this->assertStringContainsString('passages with the pairs: 1 | sources with the pairs: 1', $out);
        $this->assertStringContainsString('the bug reached the sources', $out);
    }

    public function test_it_is_one_transaction_a_failure_undoes_every_repair(): void
    {
        $a = $this->item($this->ownerNotebook, ['content' => 'F'.$this->garble('ü').'r']);
        $b = $this->item($this->ownerNotebook, ['content' => 'Gr'.$this->garble('ö').$this->garble('ß').'e']);
        DB::unprepared("CREATE FUNCTION boom() RETURNS trigger AS \$\$ BEGIN IF NEW.id = {$b} THEN RAISE EXCEPTION 'boom'; END IF; RETURN NEW; END; \$\$ LANGUAGE plpgsql");
        DB::unprepared('CREATE TRIGGER boom BEFORE UPDATE ON studio_items FOR EACH ROW EXECUTE FUNCTION boom()');

        $out = $this->fix();

        $this->assertStringContainsString('FAILED and undone', $out);
        $this->assertSame('F'.$this->garble('ü').'r', DB::table('studio_items')->where('id', $a)->value('content'), 'the first repair was undone too');
    }
}
