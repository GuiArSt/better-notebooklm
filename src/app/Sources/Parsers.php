<?php

namespace App\Sources;

use App\Ai\Exceptions\UserFacingException;
use App\Ai\SafeLog;
use fivefilters\Readability\Configuration;
use fivefilters\Readability\Readability;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Throwable;

/** Every parser returns the same shape: text, plus the character range of each page when there are pages. */
class Parsers
{
    /** What a person reads when a fetched page gave (almost) no text. */
    public const THIN_PAGE = 'This page had almost no readable text; it may need JavaScript. Upload a PDF or paste the text instead.';

    /**
     * The text of a PDF, read in a CHILD process with its own memory limit (D-040): a PDF that inflates when it is read (a few hundred
     * kilobytes that expand to gigabytes) stops the child, not the worker, and the source ends as failed with a message.
     */
    public function pdf(string $bytes, string $fallbackTitle): ParsedSource
    {
        $dir = sys_get_temp_dir();
        $pdf = tempnam($dir, 'bnlm-pdf');
        $out = tempnam($dir, 'bnlm-txt');

        try {
            file_put_contents($pdf, $bytes);
            unset($bytes);
            $mb = max(64, (int) config('retrieval.pdf_memory_mb'));
            $run = Process::timeout((int) config('retrieval.pdf_timeout_seconds'))->run([
                PHP_BINARY, '-d', "memory_limit={$mb}M", __DIR__.'/read-pdf.php', $pdf, $out,
                (string) (int) config('sources.max_pdf_pages'), (string) (int) config('sources.min_chars_per_page'),
            ]);
            $result = json_decode((string) @file_get_contents($out), true);
        } catch (ProcessTimedOutException) {
            throw new UserFacingException('This PDF took too long to read (more than '.(int) config('retrieval.pdf_timeout_seconds').' seconds). Try a smaller file or paste its text.');
        } finally {
            @unlink($pdf);
            @unlink($out);
        }

        if (! is_array($result)) {
            // No result: the child ran out of memory or time (the run's own exit status says which; neither is the user's text to log).
            SafeLog::failure('PDF read', new \RuntimeException, ['exit' => $run->exitCode() ?? -1]);

            throw new UserFacingException('This PDF is too large or too complex to read (it needs more than '.$mb.' MB of memory). Try a smaller file or paste its text.');
        }
        if (($result['ok'] ?? false) !== true) {
            throw new UserFacingException(match ($result['error'] ?? '') {
                'pages' => 'This PDF has more than '.($result['max'] ?? config('sources.max_pdf_pages')).' pages.',
                'notext' => 'This PDF has no text layer (it looks like a scan). Scanned PDFs are not supported yet.',
                default => 'This PDF could not be read.',
            });
        }

        return new ParsedSource($result['title'] !== '' ? $result['title'] : $fallbackTitle, $result['text'], $result['pages']);
    }

    public function html(string $html, string $url): ParsedSource
    {
        try {
            $article = (new Readability(new Configuration))->parse($html);
        } catch (Throwable $e) {
            throw new UserFacingException('This page could not be read.', 0, $e);
        }

        $text = trim((string) $article->textContent);
        if (! $article->hasContent() || $text === '') {
            throw new UserFacingException(self::THIN_PAGE);
        }

        // Readability keeps the page's own line breaks; squeeze runs of blank lines into paragraph breaks.
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = trim(preg_replace("/\n\s*\n\s*/", "\n\n", $text) ?? $text);

        return new ParsedSource($article->title ?: (parse_url($url, PHP_URL_HOST) ?: $url), $text);
    }

    public function plain(string $text, string $title): ParsedSource
    {
        return new ParsedSource($title, trim(str_replace("\r\n", "\n", $text)));
    }
}
