<?php

namespace Tests\Feature\Sources;

use App\Ai\Agents\RouterAgent;
use App\Ai\Exceptions\UserFacingException;
use App\Models\Notebook;
use App\Models\User;
use App\Sources\EmbedSource;
use App\Sources\Parsers;
use Axyr\Langfuse\LangfuseFacade as Langfuse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimeout;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

/** D-040 (Argus): a PDF that inflates when read must end that source as failed, never crash the worker. The read happens in a child process with its own memory limit. */
class LargePdfIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** A valid one-page PDF whose page content is a Flate stream of zeros: a few hundred KB on disk, $megabytes MB once inflated. */
    private function inflatingPdf(int $megabytes): string
    {
        $context = deflate_init(ZLIB_ENCODING_DEFLATE);
        $stream = '';
        for ($i = 0; $i < $megabytes; $i++) {
            $stream .= deflate_add($context, str_repeat('0', 1_048_576), ZLIB_NO_FLUSH);
        }
        $stream .= deflate_add($context, '', ZLIB_FINISH);

        return $this->pdf('<< /Length '.strlen($stream)." /Filter /FlateDecode >>\nstream\n$stream\nendstream");
    }

    /** @param  string  $content  the whole content object (dictionary and stream) of the single page */
    private function pdf(string $content): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            4 => $content,
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= "$id 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 6\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $out .= sprintf("%010d 00000 n \n", $offset);
        }

        return $out."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
    }

    private function textPdf(string $text): string
    {
        $stream = "BT /F1 12 Tf 72 700 Td ($text) Tj ET";

        return $this->pdf('<< /Length '.strlen($stream)." >>\nstream\n$stream\nendstream");
    }

    public function test_the_synthetic_pdf_really_is_small_on_disk_and_huge_when_read(): void
    {
        $bytes = $this->inflatingPdf(200);

        $this->assertLessThan(1_000_000, strlen($bytes));
    }

    public function test_a_pdf_that_inflates_past_the_child_memory_limit_is_refused_with_a_message_and_the_parent_lives(): void
    {
        config(['retrieval.pdf_memory_mb' => 64]);
        $bytes = $this->inflatingPdf(200);
        $before = memory_get_peak_usage(true);

        try {
            app(Parsers::class)->pdf($bytes, 'Bomb');
            $this->fail('The inflating PDF was read.');
        } catch (UserFacingException $e) {
            $this->assertStringContainsString('too large or too complex', $e->getMessage());
            $this->assertStringContainsString('64 MB', $e->getMessage());
        }

        // The parent never inflated it: its own peak did not grow by anything near 200 MB.
        $this->assertLessThan($before + 60 * 1_048_576, memory_get_peak_usage(true));
    }

    public function test_the_source_ends_as_failed_with_that_message_and_the_next_source_is_still_read(): void
    {
        Langfuse::fake();
        Embeddings::fake();
        RouterAgent::fake(fn () => 'ok');
        config(['retrieval.pdf_memory_mb' => 64]);
        $user = User::factory()->create();
        $notebook = Notebook::create(['user_id' => $user->id, 'title' => 'T']);
        Storage::disk('local')->put('sources/bomb.pdf', $this->inflatingPdf(200));
        Storage::disk('local')->put('sources/fine.pdf', $this->textPdf('Harbours and fish are in this short document about a small town.'));
        $bomb = $notebook->sources()->create(['title' => 'Bomb', 'kind' => 'pdf', 'status' => 'pending', 'file_path' => 'sources/bomb.pdf']);
        $fine = $notebook->sources()->create(['title' => 'Fine', 'kind' => 'pdf', 'status' => 'pending', 'file_path' => 'sources/fine.pdf']);

        app()->call([new EmbedSource($bomb->id), 'handle']);
        app()->call([new EmbedSource($fine->id), 'handle']);

        $bomb->refresh();
        $fine->refresh();
        $this->assertSame('failed', $bomb->status);
        $this->assertStringContainsString('too large or too complex', (string) $bomb->error);
        $this->assertSame('ready', $fine->status);
        $this->assertStringContainsString('Harbours', (string) $fine->content);
        $this->assertGreaterThan(0, $fine->passages()->count());
    }

    public function test_a_normal_pdf_still_reads_through_the_child_process_with_its_pages(): void
    {
        $parsed = app(Parsers::class)->pdf($this->textPdf('A short page about harbours and the tide.'), 'Fallback');

        $this->assertSame('Fallback', $parsed->title);
        $this->assertStringContainsString('harbours', $parsed->text);
        $this->assertSame(1, $parsed->pages[0]['page']);
    }

    public function test_a_pdf_over_the_page_limit_and_one_that_is_not_a_pdf_keep_their_own_messages(): void
    {
        config(['sources.max_pdf_pages' => 0]);
        try {
            app(Parsers::class)->pdf($this->textPdf('Some text on the only page of this file.'), 'T');
            $this->fail('No refusal.');
        } catch (UserFacingException $e) {
            $this->assertSame('This PDF has more than 0 pages.', $e->getMessage());
        }

        config(['sources.max_pdf_pages' => 300]);
        $this->expectException(UserFacingException::class);
        $this->expectExceptionMessage('This PDF could not be read.');
        app(Parsers::class)->pdf('this is not a pdf at all', 'T');
    }

    public function test_a_child_that_runs_past_its_time_ends_the_source_with_a_message_that_says_so(): void
    {
        Process::fake(fn () => throw new ProcessTimedOutException(new SymfonyTimeout(new SymfonyProcess(['php']), SymfonyTimeout::TYPE_GENERAL), new ProcessResult(new SymfonyProcess(['php']))));
        config(['retrieval.pdf_timeout_seconds' => 7]);

        $this->expectException(UserFacingException::class);
        $this->expectExceptionMessage('This PDF took too long to read (more than 7 seconds).');
        app(Parsers::class)->pdf($this->textPdf('Some text on the only page of this file.'), 'T');
    }

    public function test_the_child_raises_its_own_oom_score_so_the_kernel_stops_it_and_not_the_worker(): void
    {
        $this->assertStringContainsString('/proc/self/oom_score_adj', (string) file_get_contents(base_path('app/Sources/read-pdf.php')));
    }
}
