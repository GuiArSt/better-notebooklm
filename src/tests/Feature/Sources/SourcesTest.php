<?php

namespace Tests\Feature\Sources;

use App\Ai\Agents\RouterAgent;
use App\Models\Notebook;
use App\Models\NotebookOverview;
use App\Models\Passage;
use App\Models\Source;
use App\Models\User;
use App\Sources\EmbedSource;
use App\Sources\SourceSummary;
use App\Sources\UrlFetcher;
use Axyr\Langfuse\LangfuseFacade as Langfuse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use JeffersonGoncalves\SsrfGuard\Exceptions\BlockedHostException;
use Laravel\Ai\Embeddings;
use Tests\TestCase;

class SourcesTest extends TestCase
{
    use RefreshDatabase;

    /** A tiny valid PDF: one text string per page. */
    private function pdf(array $pageTexts): string
    {
        $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>'];
        $kids = [];
        $n = 3;
        $fontId = 3 + count($pageTexts) * 2;
        foreach ($pageTexts as $text) {
            $pageId = $n++;
            $contentId = $n++;
            $kids[] = "$pageId 0 R";
            $stream = 'BT /F1 12 Tf 72 700 Td ('.str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text).') Tj ET';
            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents $contentId 0 R /Resources << /Font << /F1 $fontId 0 R >> >> >>";
            $objects[$contentId] = '<< /Length '.strlen($stream).' >>'."\nstream\n$stream\nendstream";
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';
        $objects[$fontId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        ksort($objects);

        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= "$id 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($out);
        $out .= 'xref\n0 '.(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $out .= sprintf("%010d 00000 n \n", $o);
        }
        $out .= 'trailer\n<< /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";

        return str_replace('\n', "\n", $out);
    }

    private function fakes(): void
    {
        Langfuse::fake();
        Embeddings::fake();
        RouterAgent::fake(fn () => 'A short summary of the source.');
    }

    private function notebook(?User $user = null): Notebook
    {
        return Notebook::create(['user_id' => ($user ?? User::factory()->create())->id, 'title' => 'T']);
    }

    // ---- The SSRF guard: written first, all must be refused -------------------------------------------------

    public function test_the_fetcher_refuses_every_internal_address(): void
    {
        Http::fake(); // nothing may reach the network
        $fetcher = app(UrlFetcher::class);

        foreach ([
            'http://127.0.0.1/', 'http://10.0.0.1/', 'http://169.254.169.254/latest/meta-data/', 'http://[::1]/',
            'http://localhost/', 'http://0x7f000001/', 'http://2130706433/', 'http://[::ffff:127.0.0.1]/',
            'file:///etc/passwd', 'ftp://93.184.216.34/', 'gopher://93.184.216.34/', 'http://user@127.0.0.1/',
            'http://93.184.216.34:8080/', 'http://93.184.216.34:22/', 'https://93.184.216.34:6379/',
        ] as $url) {
            $this->assertFalse($fetcher->isPublicUrl($url), "$url must be refused");
            try {
                $fetcher->fetchPage($url);
                $this->fail("$url was fetched");
            } catch (BlockedHostException) {
                $this->addToAssertionCount(1);
            }
        }

        Http::assertNothingSent();
    }

    public function test_a_public_page_that_redirects_to_a_private_address_is_refused(): void
    {
        Http::fake(['93.184.216.34/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/'])]);

        $this->expectException(BlockedHostException::class);
        app(UrlFetcher::class)->fetchPage('http://93.184.216.34/start');
    }

    public function test_a_redirect_to_a_forbidden_port_is_refused(): void
    {
        Http::fake(['93.184.216.34/*' => Http::response('', 302, ['Location' => 'http://93.184.216.34:8080/'])]);

        $this->expectException(BlockedHostException::class);
        app(UrlFetcher::class)->fetchPage('http://93.184.216.34/start');
    }

    public function test_only_html_or_text_is_accepted(): void
    {
        Http::fake(['93.184.216.34/*' => Http::response('%PDF', 200, ['Content-Type' => 'application/pdf'])]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only web pages and plain text');
        app(UrlFetcher::class)->fetchPage('http://93.184.216.34/file');
    }

    public function test_the_endpoint_refuses_an_internal_address_with_a_clear_message(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $notebook = $this->notebook($user);

        $this->actingAs($user)->post("/notebooks/{$notebook->id}/sources", ['url' => 'http://169.254.169.254/'])
            ->assertSessionHasErrors('url');

        $this->assertSame(0, Source::count());
        Queue::assertNothingPushed();
    }

    // ---- Web page --------------------------------------------------------------------------------------------

    public function test_a_web_page_becomes_a_source_with_text_a_summary_and_an_overview(): void
    {
        $this->fakes();
        RouterAgent::fake(fn () => '{"summary":"A page about harbours.","questions":["Q one?","Q two?","Q three?","Q four?"]}');
        $html = '<html><head><title>Harbours of the North</title></head><body><nav>menu menu</nav><article><h1>Harbours of the North</h1>'
            .'<p>'.str_repeat('The harbour at Skagen is the busiest fishing port in the region and has been since 1840. ', 4).'</p>'
            .'<p>'.str_repeat('Its fish auction starts at five in the morning every weekday throughout the year. ', 4).'</p></article></body></html>';
        Http::fake(['93.184.216.34/*' => Http::response($html, 200, ['Content-Type' => 'text/html; charset=utf-8'])]);
        $user = User::factory()->create();
        $notebook = $this->notebook($user);

        $this->actingAs($user)->post("/notebooks/{$notebook->id}/sources", ['url' => 'http://93.184.216.34/harbours'])->assertRedirect();
        $source = Source::firstOrFail();
        $this->assertSame('web', $source->kind);

        app()->call([new EmbedSource($source->id), 'handle']);

        $source->refresh();
        $this->assertSame('ready', $source->status, (string) $source->error);
        $this->assertStringContainsString('Skagen', (string) $source->content);
        $this->assertStringNotContainsString('menu menu', (string) $source->content);
        $this->assertNotNull($source->summary);
        $this->assertGreaterThan(0, Passage::where('source_id', $source->id)->count());

        // The overview: summary and at most three questions.
        $overview = NotebookOverview::where('notebook_id', $notebook->id)->first();
        $this->assertSame('A page about harbours.', $overview->summary);
        $this->assertCount(3, $overview->questions);
        $this->actingAs($user)->getJson("/notebooks/{$notebook->id}/overview")->assertOk()->assertJsonCount(3, 'questions');
    }

    public function test_a_page_that_cannot_be_fetched_marks_the_source_failed_with_the_reason(): void
    {
        $this->fakes();
        Http::fake(['93.184.216.34/*' => Http::response('nope', 404)]);
        $notebook = $this->notebook();
        $source = $notebook->sources()->create(['kind' => 'web', 'origin_url' => 'http://93.184.216.34/x', 'title' => 'x', 'status' => 'pending']);

        app()->call([new EmbedSource($source->id), 'handle']);

        $source->refresh();
        $this->assertSame('failed', $source->status);
        $this->assertStringContainsString('404', (string) $source->error);
        $this->assertSame(0, Passage::count());
    }

    // ---- PDF -------------------------------------------------------------------------------------------------

    public function test_a_pdf_keeps_page_numbers_on_its_passages_and_the_viewer_json_has_the_offsets(): void
    {
        $this->fakes();
        config(['retrieval.chunk_chars' => 80]);
        $user = User::factory()->create();
        $notebook = $this->notebook($user);
        $page1 = 'The treaty was signed in Vienna on the fourth of May.';
        $page2 = 'Ratification followed in the autumn of that year.';
        $file = UploadedFile::fake()->createWithContent('treaty.pdf', $this->pdf([$page1, $page2]));

        $this->actingAs($user)->post("/notebooks/{$notebook->id}/sources", ['file' => $file])->assertRedirect();
        $source = Source::firstOrFail();
        $this->assertSame('pdf', $source->kind);
        $this->assertSame('treaty.pdf', $source->title);

        app()->call([new EmbedSource($source->id), 'handle']);

        $source->refresh();
        $this->assertSame('ready', $source->status, (string) $source->error);
        $this->assertCount(2, $source->pages);
        $passages = Passage::where('source_id', $source->id)->orderBy('position')->get();
        $this->assertSame([1, 2], $passages->pluck('page')->unique()->values()->all());

        $first = $passages->firstWhere('page', 1);
        $this->assertStringContainsString('Vienna', $first->content);
        $this->assertSame($first->content, mb_substr($source->content, $first->start_offset, $first->end_offset - $first->start_offset));
        $second = $passages->firstWhere('page', 2);
        $this->assertStringContainsString('Ratification', $second->content);

        $json = $this->actingAs($user)->getJson("/notebooks/{$notebook->id}/sources/{$source->id}")->assertOk()->json();
        $this->assertSame('pdf', $json['kind']);
        $this->assertSame($source->content, $json['content']);
        $this->assertCount($passages->count(), $json['passages']);
        $this->assertSame(1, $json['passages'][0]['page']);
    }

    public function test_a_pdf_without_a_text_layer_fails_honestly(): void
    {
        $this->fakes();
        $notebook = $this->notebook();
        $path = Storage::disk('local')->put('sources/scan.pdf', $this->pdf(['']));
        $source = $notebook->sources()->create(['kind' => 'pdf', 'file_path' => 'sources/scan.pdf', 'title' => 'scan', 'status' => 'pending']);

        app()->call([new EmbedSource($source->id), 'handle']);

        $source->refresh();
        $this->assertSame('failed', $source->status);
        $this->assertStringContainsString('no text layer', (string) $source->error);
    }

    // ---- Upload rules ----------------------------------------------------------------------------------------

    public function test_a_text_file_is_read_at_once_and_the_wrong_type_or_two_inputs_are_refused(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $notebook = $this->notebook($user);

        $this->actingAs($user)->post("/notebooks/{$notebook->id}/sources", ['file' => UploadedFile::fake()->createWithContent('notes.txt', "Käse ist gut.\n\nBrot auch.")])->assertRedirect();
        $this->assertSame("Käse ist gut.\n\nBrot auch.", Source::first()->content);

        $this->actingAs($user)->post("/notebooks/{$notebook->id}/sources", ['file' => UploadedFile::fake()->create('virus.exe', 10)])->assertSessionHasErrors('file');
        $this->actingAs($user)->post("/notebooks/{$notebook->id}/sources", ['title' => 't', 'content' => 'x', 'url' => 'http://93.184.216.34/'])->assertSessionHasErrors('content');
        $this->assertSame(1, Source::count());
    }

    public function test_another_users_source_cannot_be_read_or_deleted(): void
    {
        $source = $this->notebook()->sources()->create(['title' => 's', 'content' => 'secret', 'status' => 'ready']);
        $other = User::factory()->create();

        $this->actingAs($other)->getJson("/notebooks/{$source->notebook_id}/sources/{$source->id}")->assertNotFound();
        $this->actingAs($other)->delete("/notebooks/{$source->notebook_id}/sources/{$source->id}")->assertNotFound();
    }

    // ---- Overview parsing ------------------------------------------------------------------------------------

    public function test_the_overview_parser_accepts_fenced_json_and_rejects_nonsense(): void
    {
        $parser = app(SourceSummary::class);

        $ok = $parser->parse("Here you go:\n```json\n{\"summary\":\"S\",\"questions\":[\"a?\",\"b?\"]}\n```");
        $this->assertSame(['summary' => 'S', 'questions' => ['a?', 'b?']], $ok);
        $this->assertNull($parser->parse('no json at all'));
        $this->assertNull($parser->parse('{"summary": 5}'));
    }

    public function test_the_request_options_pin_the_ip_and_never_let_curl_or_guzzle_follow_redirects(): void
    {
        $options = (fn () => $this->buildOptions(['example.test:80:93.184.216.34'], []))->call(app(UrlFetcher::class));

        $this->assertFalse($options['allow_redirects']);
        $this->assertSame(['example.test:80:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);
        $this->assertArrayNotHasKey(CURLOPT_FOLLOWLOCATION, $options['curl']);
    }
}
