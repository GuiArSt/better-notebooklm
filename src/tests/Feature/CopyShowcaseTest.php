<?php

namespace Tests\Feature;

use App\Frontend\Featured;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;
use Tests\TestCase;

/** scripts/demo/copy-showcase.php: a deep copy of a notebook and its finished Studio items into another account, with no model call and no ledger row. */
class CopyShowcaseTest extends TestCase
{
    use RefreshDatabase;

    private int $author;

    private int $reviewer;

    private int $notebook;

    /** @var array<string, int> */
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->author = User::factory()->create(['email' => 'author@example.test', 'role' => 'owner'])->id;
        $this->reviewer = User::factory()->create(['email' => 'reviewer@example.test'])->id;
        $this->notebook = $this->fixture();
    }

    private function vec(int $at): string
    {
        $v = array_fill(0, (int) config('embeddings.dimensions'), 0.0);
        $v[$at] = 1.0;

        return '['.implode(',', $v).']';
    }

    private function fixture(): int
    {
        $now = now();
        $nb = DB::table('notebooks')->insertGetId(['user_id' => $this->author, 'title' => 'Die Wasserstelle', 'emoji' => '💧', 'featured_slug' => null, 'icon_status' => 'done', 'icon_path' => "icons/{$this->author}/9.webp", 'icon_sha256' => str_repeat('a', 64), 'icon_width' => 256, 'icon_height' => 256, 'created_at' => $now, 'updated_at' => $now]);
        Storage::disk('local')->put("icons/{$this->author}/9.webp", 'ICON-BYTES');

        $s1 = DB::table('sources')->insertGetId(['notebook_id' => $nb, 'kind' => 'text', 'title' => 'Quelle eins', 'content' => 'Text eins.', 'status' => 'ready', 'checked' => true, 'summary' => 'Zusammenfassung', 'language' => 'de', 'chars' => 10, 'created_at' => $now, 'updated_at' => $now]);
        $s2 = DB::table('sources')->insertGetId(['notebook_id' => $nb, 'kind' => 'pdf', 'title' => 'Quelle zwei', 'content' => 'Text zwei.', 'status' => 'ready', 'checked' => true, 'file_path' => "sources/$nb/zwei.pdf", 'pages' => json_encode([['page' => 1, 'start' => 0, 'end' => 10]]), 'created_at' => $now, 'updated_at' => $now]);
        DB::table('sources')->insert(['notebook_id' => $nb, 'kind' => 'web', 'title' => 'Kaputt', 'status' => 'failed', 'error' => 'x', 'checked' => true, 'created_at' => $now, 'updated_at' => $now]);
        Storage::disk('local')->put("sources/$nb/zwei.pdf", 'PDF-BYTES');

        foreach ([['a', $s1, 0], ['b', $s1, 1], ['c', $s2, 0]] as [$key, $source, $pos]) {
            $this->p[$key] = DB::table('passages')->insertGetId(['notebook_id' => $nb, 'source_id' => $source, 'position' => $pos, 'page' => 1, 'start_offset' => $pos * 10, 'end_offset' => $pos * 10 + 9, 'content' => "Passage $key", 'embedding' => $this->vec($pos + ord($key)), 'created_at' => $now, 'updated_at' => $now]);
        }
        $this->p['s1'] = $s1;
        $this->p['s2'] = $s2;
        $cite = fn (string $k, int $s, string $t) => ['id' => $this->p[$k], 'source_id' => $s, 'source_title' => $t, 'page' => 1, 'start' => 0, 'end' => 9, 'quote' => "Passage $k"];

        DB::table('notes')->insert(['notebook_id' => $nb, 'title' => 'Notiz', 'content' => "Ein Satz [P{$this->p['a']}].", 'citations' => json_encode([$cite('a', $s1, 'Quelle eins')]), 'from_chat' => true, 'checked' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('notebook_overviews')->insert(['notebook_id' => $nb, 'summary' => 'Ein Überblick.', 'questions' => json_encode(['Eins?', 'Zwei?', 'Drei?']), 'status' => 'ready', 'created_at' => $now, 'updated_at' => $now]);

        $settings = fn (string $kind) => json_encode(['kind' => $kind, 'sources' => [['id' => $s1, 'title' => 'Quelle eins'], ['id' => $s2, 'title' => 'Quelle zwei']], 'language' => 'de', 'regenerated_from' => 1, 'cost_micro_usd' => 1234]);
        $item = fn (string $kind, array $more = []) => DB::table('studio_items')->insertGetId($more + ['notebook_id' => $nb, 'user_id' => $this->author, 'kind' => $kind, 'title' => ucfirst($kind), 'status' => 'done', 'step_n' => 0, 'step_total' => 0, 'settings' => $settings($kind), 'created_at' => $now, 'updated_at' => $now]);

        $both = json_encode([$cite('a', $s1, 'Quelle eins'), $cite('c', $s2, 'Quelle zwei')]);
        $item('briefing', ['content' => "Fakt eins [P{$this->p['a']}]. Fakt zwei [P{$this->p['b']}, P{$this->p['c']}].", 'citations' => $both]);
        $item('faq', ['content' => "### Frage\nAntwort [P{$this->p['c']}].", 'citations' => $both]);
        $item('mindmap', ['tree' => json_encode(['title' => 'Karte', 'children' => [['label' => 'Ast', 'refs' => [$this->p['a']], 'children' => [['label' => 'Blatt', 'refs' => [$this->p['b']], 'children' => []]]]]]), 'citations' => $both]);
        $item('quiz', ['quiz' => json_encode([['question' => 'F?', 'options' => ['a', 'b', 'c', 'd'], 'answer' => 0, 'refs' => [$this->p['c']]]]), 'citations' => $both]);
        $item('slides', ['slides' => json_encode([['template' => 'bullets', 'fields' => ['heading' => 'H', 'items' => ['x']], 'citations' => ['P'.$this->p['a'], 'P'.$this->p['b']]]]), 'citations' => $both]);
        $audio = $item('audio', ['script' => json_encode([['speaker' => 'A', 'text' => 'Hallo']]), 'file_path' => "studio/$nb/{$this->author}1.mp3"]);
        Storage::disk('local')->put("studio/$nb/{$this->author}1.mp3", 'MP3-BYTES');
        $info = $item('infographic', ['infographic' => json_encode(['title' => 'T', 'points' => [['heading' => 'H', 'text' => 't', 'refs' => ['P'.$this->p['a']]]]]), 'citations' => $both]);
        DB::table('studio_items')->where('id', $info)->update(['file_path' => "studio/$nb/{$info}.png"]);
        Storage::disk('local')->put("studio/$nb/{$info}.png", 'PNG-BYTES');
        // Not finished: must be skipped.
        $item('briefing', ['title' => 'Fehler', 'status' => 'failed', 'error' => 'x']);
        $item('quiz', ['title' => 'Läuft', 'status' => 'running', 'step' => 'Writing']);
        $item('faq', ['title' => 'Wartet', 'status' => 'pending']);
        unset($audio);

        return $nb;
    }

    /** @return string the script's output */
    private function copy(array $env = []): string
    {
        $env += ['SHOWCASE_SOURCE' => (string) $this->notebook, 'SHOWCASE_TARGET' => 'reviewer@example.test'];
        foreach ($env as $k => $v) {
            putenv("$k=$v");
        }
        ob_start();
        try {
            include base_path('../scripts/demo/copy-showcase.php');
        } finally {
            $out = (string) ob_get_clean();
            foreach (array_keys($env) as $k) {
                putenv($k);
            }
        }

        return $out;
    }

    private function copied(): ?object
    {
        return DB::table('notebooks')->where('user_id', $this->reviewer)->first();
    }

    public function test_it_copies_the_notebook_its_sources_passages_notes_overview_and_the_finished_items_and_prints_only_counts(): void
    {
        $out = $this->copy();

        $nb = $this->copied();
        $this->assertNotNull($nb);
        $this->assertSame('Die Wasserstelle', $nb->title);
        $this->assertSame(2, DB::table('sources')->where('notebook_id', $nb->id)->count(), 'the failed source is skipped');
        $this->assertSame(3, DB::table('passages')->where('notebook_id', $nb->id)->count());
        $this->assertSame(1, DB::table('notes')->where('notebook_id', $nb->id)->count());
        $this->assertSame('ready', DB::table('notebook_overviews')->where('notebook_id', $nb->id)->value('status'));
        $this->assertSame(['audio', 'briefing', 'faq', 'infographic', 'mindmap', 'quiz', 'slides'], DB::table('studio_items')->where('notebook_id', $nb->id)->orderBy('kind')->pluck('kind')->all(), 'only the done items, one of each');
        $this->assertSame(0, DB::table('studio_items')->where('notebook_id', $nb->id)->where('status', '!=', 'done')->count());
        $this->assertSame($this->reviewer, (int) DB::table('studio_items')->where('notebook_id', $nb->id)->value('user_id'));

        // Counts only: none of the notebook's words are printed.
        $this->assertStringContainsString('studio items copied: 7', $out);
        $this->assertStringContainsString('skipped, not finished: 3', $out);
        foreach (['Wasserstelle', 'Quelle eins', 'Passage a', 'Hallo', 'Überblick', 'Fakt eins'] as $word) {
            $this->assertStringNotContainsString($word, $out);
        }
    }

    public function test_the_embeddings_are_copied_not_recomputed_and_no_model_is_called_no_ledger_row_and_no_job(): void
    {
        Embeddings::fake(); // every model call goes through the Router, which always writes a ledger row: none is written below
        $this->copy();

        $nb = $this->copied();
        $same = DB::selectOne('select count(*) as n from passages a join passages b on a.position = b.position and a.content = b.content and a.embedding = b.embedding and a.id <> b.id where a.notebook_id = ? and b.notebook_id = ?', [$nb->id, $this->notebook]);
        $this->assertSame(3, (int) $same->n);
        $this->assertSame(0, DB::table('usage_events')->count());
        $this->assertSame(0, DB::table('ledger_reservations')->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_every_reference_points_at_the_copys_own_passages_and_sources(): void
    {
        $out = $this->copy();

        $nb = $this->copied();
        $new = DB::table('passages')->where('notebook_id', $nb->id)->pluck('id', 'content');   // "Passage a" => new id
        $newSource = DB::table('sources')->where('notebook_id', $nb->id)->pluck('id', 'title');
        $item = fn (string $kind) => DB::table('studio_items')->where('notebook_id', $nb->id)->where('kind', $kind)->first();

        $briefing = $item('briefing');
        $this->assertSame("Fakt eins [P{$new['Passage a']}]. Fakt zwei [P{$new['Passage b']}, P{$new['Passage c']}].", $briefing->content);
        $cites = json_decode($briefing->citations, true);
        $this->assertSame([$new['Passage a'], $new['Passage c']], array_column($cites, 'id'));
        $this->assertSame([$newSource['Quelle eins'], $newSource['Quelle zwei']], array_column($cites, 'source_id'));
        $this->assertNotContains($this->p['a'], array_column($cites, 'id'), 'not the original ids');

        $tree = json_decode($item('mindmap')->tree, true);
        $this->assertSame([$new['Passage a']], $tree['children'][0]['refs']);
        $this->assertSame([$new['Passage b']], $tree['children'][0]['children'][0]['refs']);
        $this->assertSame([$new['Passage c']], json_decode($item('quiz')->quiz, true)[0]['refs']);
        $this->assertSame(['P'.$new['Passage a'], 'P'.$new['Passage b']], json_decode($item('slides')->slides, true)[0]['citations']);
        $this->assertSame(['P'.$new['Passage a']], json_decode($item('infographic')->infographic, true)['points'][0]['refs']);
        $note = DB::table('notes')->where('notebook_id', $nb->id)->first();
        $this->assertSame("Ein Satz [P{$new['Passage a']}].", $note->content);
        $this->assertSame([$new['Passage a']], array_column(json_decode($note->citations, true), 'id'));

        $settings = json_decode($item('faq')->settings, true);
        $this->assertSame([$newSource['Quelle eins'], $newSource['Quelle zwei']], array_column($settings['sources'], 'id'));
        $this->assertSame($this->notebook, $settings['copied_from_notebook']);
        $this->assertArrayNotHasKey('regenerated_from', $settings);
        $this->assertSame(1234, $settings['cost_micro_usd'], 'the settings are otherwise kept as they were');
        $this->assertStringContainsString('references that pointed at an uncopied passage and were removed: 0', $out);
    }

    public function test_a_mark_that_points_at_a_passage_that_was_not_copied_is_removed_never_left_to_point_at_someone_elses(): void
    {
        $stranger = DB::table('passages')->insertGetId(['notebook_id' => $this->notebook, 'source_id' => DB::table('sources')->where('status', 'failed')->value('id'), 'position' => 0, 'start_offset' => 0, 'end_offset' => 1, 'content' => 'Stranger', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('studio_items')->where('notebook_id', $this->notebook)->where('kind', 'faq')->where('status', 'done')->update(['content' => "Keep [P{$this->p['a']}] drop [P{$stranger}] both [P{$this->p['b']}, P{$stranger}]."]);

        $out = $this->copy();

        $nb = $this->copied();
        $a = DB::table('passages')->where('notebook_id', $nb->id)->where('content', 'Passage a')->value('id');
        $b = DB::table('passages')->where('notebook_id', $nb->id)->where('content', 'Passage b')->value('id');
        $this->assertSame("Keep [P$a] drop  both [P$b].", DB::table('studio_items')->where('notebook_id', $nb->id)->where('kind', 'faq')->value('content'));
        $this->assertStringContainsString('were removed: 2', $out);
    }

    public function test_every_stored_file_is_copied_to_a_new_path_under_the_new_notebook_with_the_same_bytes(): void
    {
        $this->copy();

        $nb = $this->copied();
        $disk = Storage::disk('local');
        $audio = DB::table('studio_items')->where('notebook_id', $nb->id)->where('kind', 'audio')->first();
        $info = DB::table('studio_items')->where('notebook_id', $nb->id)->where('kind', 'infographic')->first();
        $source = DB::table('sources')->where('notebook_id', $nb->id)->where('kind', 'pdf')->first();
        $originals = [DB::table('studio_items')->where('notebook_id', $this->notebook)->where('kind', 'audio')->value('file_path'), "icons/{$this->author}/9.webp", "sources/{$this->notebook}/zwei.pdf"];

        $this->assertSame("studio/{$nb->id}/{$audio->id}.mp3", $audio->file_path);
        $this->assertSame("studio/{$nb->id}/{$info->id}.png", $info->file_path);
        $this->assertSame("sources/{$nb->id}/zwei.pdf", $source->file_path);
        $this->assertSame("icons/{$this->reviewer}/{$nb->id}.webp", $nb->icon_path);
        $this->assertSame('MP3-BYTES', $disk->get($audio->file_path));
        $this->assertSame('PNG-BYTES', $disk->get($info->file_path));
        $this->assertSame('PDF-BYTES', $disk->get($source->file_path));
        $this->assertSame('ICON-BYTES', $disk->get($nb->icon_path));
        foreach ($originals as $original) {
            $this->assertTrue($disk->exists($original), 'the originals are untouched');
        }
        $this->assertNotContains($audio->file_path, $originals);
    }

    public function test_it_is_idempotent_a_second_run_refuses_and_changes_nothing(): void
    {
        $this->copy();
        $counts = fn () => [DB::table('notebooks')->count(), DB::table('studio_items')->count(), DB::table('passages')->count(), count(Storage::disk('local')->allFiles())];
        $before = $counts();

        $out = $this->copy();

        $this->assertStringContainsString('REFUSED: that user already holds items copied from notebook', $out);
        $this->assertSame($before, $counts());
    }

    public function test_a_failure_halfway_undoes_every_row_and_deletes_every_copied_file(): void
    {
        DB::unprepared("CREATE FUNCTION boom() RETURNS trigger AS \$\$ BEGIN IF NEW.user_id = {$this->reviewer} AND NEW.kind = 'infographic' THEN RAISE EXCEPTION 'boom'; END IF; RETURN NEW; END; \$\$ LANGUAGE plpgsql");
        DB::unprepared('CREATE TRIGGER boom BEFORE INSERT ON studio_items FOR EACH ROW EXECUTE FUNCTION boom()');
        $filesBefore = Storage::disk('local')->allFiles();
        $rows = fn () => [DB::table('notebooks')->count(), DB::table('sources')->count(), DB::table('passages')->count(), DB::table('studio_items')->count(), DB::table('notes')->count()];
        $before = $rows();

        $out = $this->copy();

        $this->assertStringContainsString('FAILED and undone', $out);
        $this->assertSame($before, $rows(), 'not one row was kept');
        $this->assertEqualsCanonicalizing($filesBefore, Storage::disk('local')->allFiles(), 'every file the copy made was deleted');
    }

    public function test_a_dry_run_shows_the_counts_and_keeps_nothing(): void
    {
        $filesBefore = Storage::disk('local')->allFiles();

        $out = $this->copy(['SHOWCASE_DRY' => '1']);

        $this->assertStringContainsString('DRY RUN: nothing was kept', $out);
        $this->assertStringContainsString('studio items copied: 7', $out);
        $this->assertNull($this->copied());
        $this->assertEqualsCanonicalizing($filesBefore, Storage::disk('local')->allFiles());
    }

    public function test_the_copy_becomes_the_featured_notebook_unless_the_user_already_has_one_or_it_is_switched_off(): void
    {
        $out = $this->copy();
        $this->assertSame(Featured::SLUG, $this->copied()->featured_slug);
        $this->assertStringContainsString('featured: set', $out);
        $this->assertSame($this->copied()->id, Featured::open(User::find($this->reviewer))->id, 'the home page card opens the showcase');

        // A second user who already has a featured notebook keeps it; another, with the switch off, gets none.
        $has = User::factory()->create(['email' => 'has@example.test'])->id;
        DB::table('notebooks')->insert(['user_id' => $has, 'title' => 'About this demo', 'emoji' => '🧪', 'featured_slug' => Featured::SLUG, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertStringContainsString('not set (the user already has a featured notebook', $this->copy(['SHOWCASE_TARGET' => 'has@example.test']));
        $this->assertSame(1, DB::table('notebooks')->where('user_id', $has)->where('featured_slug', Featured::SLUG)->count());

        User::factory()->create(['email' => 'off@example.test']);
        $this->copy(['SHOWCASE_TARGET' => 'off@example.test', 'SHOWCASE_FEATURED' => '0']);
        $this->assertNull(DB::table('notebooks')->where('user_id', User::where('email', 'off@example.test')->value('id'))->value('featured_slug'));
    }

    public function test_it_refuses_bad_arguments_and_a_notebook_with_nothing_finished(): void
    {
        $this->assertStringContainsString('REFUSED: set SHOWCASE_SOURCE', $this->copy(['SHOWCASE_SOURCE' => '0']));
        $this->assertStringContainsString('there is no notebook 999999', $this->copy(['SHOWCASE_SOURCE' => '999999']));
        $this->assertStringContainsString('there is no user with that email', $this->copy(['SHOWCASE_TARGET' => 'nobody@example.test']));

        DB::table('studio_items')->where('notebook_id', $this->notebook)->update(['status' => 'failed']);
        $this->assertStringContainsString('has no finished Studio item', $this->copy());
        $this->assertNull($this->copied());
    }

    /** A second, small notebook of the same author: one source, one passage, one finished briefing citing it. */
    private function second(string $title): int
    {
        $now = now();
        $nb = DB::table('notebooks')->insertGetId(['user_id' => $this->author, 'title' => $title, 'emoji' => '📘', 'created_at' => $now, 'updated_at' => $now]);
        $s = DB::table('sources')->insertGetId(['notebook_id' => $nb, 'kind' => 'text', 'title' => 'Eine Quelle', 'content' => 'Inhalt.', 'status' => 'ready', 'checked' => true, 'created_at' => $now, 'updated_at' => $now]);
        $p = DB::table('passages')->insertGetId(['notebook_id' => $nb, 'source_id' => $s, 'position' => 0, 'start_offset' => 0, 'end_offset' => 6, 'content' => 'Inhalt.', 'embedding' => $this->vec(5), 'created_at' => $now, 'updated_at' => $now]);
        DB::table('studio_items')->insert(['notebook_id' => $nb, 'user_id' => $this->author, 'kind' => 'briefing', 'title' => 'Kurz', 'status' => 'done', 'step_n' => 0, 'step_total' => 0, 'content' => "Fakt [P$p].", 'citations' => json_encode([['id' => $p, 'source_id' => $s, 'source_title' => 'Eine Quelle', 'quote' => 'Inhalt.']]), 'created_at' => $now, 'updated_at' => $now]);

        return $nb;
    }

    private function copiedNotebooks(): array
    {
        return DB::table('notebooks')->where('user_id', $this->reviewer)->orderBy('id')->pluck('title')->all();
    }

    public function test_a_list_of_notebooks_is_copied_one_after_the_other_and_only_the_first_is_featured(): void
    {
        $two = $this->second('Deutschlands KI, eine Übersicht');

        $out = $this->copy(['SHOWCASE_SOURCE' => $this->notebook.','.$two]);

        $this->assertSame(['Die Wasserstelle', 'Deutschlands KI, eine Übersicht'], $this->copiedNotebooks(), 'both, in the order of the list');
        $this->assertStringContainsString('== notebook 1 of 2: source '.$this->notebook, $out);
        $this->assertStringContainsString('== notebook 2 of 2: source '.$two, $out);
        $slugs = DB::table('notebooks')->where('user_id', $this->reviewer)->orderBy('id')->pluck('featured_slug')->all();
        $this->assertSame([Featured::SLUG, null], $slugs, 'the first of the list is the featured one');
        $this->assertSame(7 + 1, DB::table('studio_items')->where('user_id', $this->reviewer)->count());
        $this->assertSame(0, DB::table('usage_events')->count());
    }

    public function test_each_notebook_of_a_list_is_idempotent_on_its_own(): void
    {
        $two = $this->second('Zweites');
        $this->copy(['SHOWCASE_SOURCE' => (string) $this->notebook]);
        $three = $this->second('Drittes');

        $out = $this->copy(['SHOWCASE_SOURCE' => $this->notebook.','.$two.','.$three]);

        $this->assertSame(1, substr_count($out, 'REFUSED: that user already holds items copied from notebook '.$this->notebook));
        $this->assertSame(['Die Wasserstelle', 'Zweites', 'Drittes'], $this->copiedNotebooks(), 'the one already copied is not copied again; the other two are');

        $again = $this->copy(['SHOWCASE_SOURCE' => $this->notebook.','.$two.','.$three]);
        $this->assertSame(3, substr_count($again, 'REFUSED: that user already holds'));
        $this->assertCount(3, $this->copiedNotebooks());
    }

    public function test_a_bad_id_in_the_list_is_reported_and_the_others_still_copy_and_junk_and_repeats_are_ignored(): void
    {
        $two = $this->second('Zweites');

        $out = $this->copy(['SHOWCASE_SOURCE' => " {$this->notebook} , 999999,, abc, {$two},{$two} "]);

        $this->assertStringContainsString('there is no notebook 999999', $out);
        $this->assertSame(3, substr_count($out, '== notebook '), 'three real ids: the junk and the repeat are dropped');
        $this->assertSame(['Die Wasserstelle', 'Zweites'], $this->copiedNotebooks());
    }

    public function test_a_failure_in_one_notebook_of_the_list_keeps_nothing_of_it_but_the_others_stay(): void
    {
        $two = $this->second('Zweites');
        DB::unprepared("CREATE FUNCTION boom() RETURNS trigger AS \$\$ BEGIN IF NEW.user_id = {$this->reviewer} AND NEW.kind = 'infographic' THEN RAISE EXCEPTION 'boom'; END IF; RETURN NEW; END; \$\$ LANGUAGE plpgsql");
        DB::unprepared('CREATE TRIGGER boom BEFORE INSERT ON studio_items FOR EACH ROW EXECUTE FUNCTION boom()');

        $out = $this->copy(['SHOWCASE_SOURCE' => $this->notebook.','.$two]);

        $this->assertStringContainsString('FAILED and undone', $out);
        $this->assertSame(['Zweites'], $this->copiedNotebooks(), 'the failed one left nothing, the second was copied');
    }

    public function test_only_a_notebook_owned_by_an_owner_can_be_copied_a_reviewers_or_testers_never(): void
    {
        foreach (['reviewer', 'member'] as $role) {
            DB::table('users')->where('id', $this->author)->update(['role' => $role]);

            $out = $this->copy();

            $this->assertStringContainsString("REFUSED: notebook {$this->notebook} does not belong to an owner (its user has the role $role)", $out);
            $this->assertStringContainsString('Nothing was changed', $out);
            $this->assertNull($this->copied(), "a $role's notebook was not copied");
            $this->assertSame(0, DB::table('studio_items')->where('user_id', $this->reviewer)->count());
        }

        // A rehearsal is refused the same way (it must not be a way to look inside), and the owner's notebook goes through.
        DB::table('users')->where('id', $this->author)->update(['role' => 'reviewer']);
        $this->assertStringContainsString('REFUSED: notebook', $this->copy(['SHOWCASE_DRY' => '1']));
        DB::table('users')->where('id', $this->author)->update(['role' => 'owner']);
        $this->assertStringContainsString('studio items copied: 7', $this->copy());
    }

    public function test_in_a_list_the_notebook_of_a_non_owner_is_refused_and_the_owners_are_still_copied(): void
    {
        $other = User::factory()->create(['role' => 'reviewer'])->id;
        $theirs = DB::table('notebooks')->insertGetId(['user_id' => $other, 'title' => 'Fremdes', 'emoji' => '📕', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('studio_items')->insert(['notebook_id' => $theirs, 'user_id' => $other, 'kind' => 'briefing', 'title' => 'B', 'status' => 'done', 'step_n' => 0, 'step_total' => 0, 'content' => 'x', 'created_at' => now(), 'updated_at' => now()]);

        $out = $this->copy(['SHOWCASE_SOURCE' => $theirs.','.$this->notebook]);

        $this->assertStringContainsString("REFUSED: notebook $theirs does not belong to an owner", $out);
        $this->assertSame(['Die Wasserstelle'], $this->copiedNotebooks());
    }

    public function test_a_notebook_that_belongs_to_nobody_is_refused_too(): void
    {
        DB::statement('ALTER TABLE notebooks DISABLE TRIGGER ALL');
        DB::table('notebooks')->where('id', $this->notebook)->update(['user_id' => 999999]);

        $this->assertStringContainsString('the role none', $this->copy());
        $this->assertNull($this->copied());
    }
}
