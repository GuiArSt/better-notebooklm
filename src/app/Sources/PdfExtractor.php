<?php

namespace App\Sources;

use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Reads the text of a PDF. Pure PHP, with no framework: it runs inside the child process of `read-pdf.php`, which has its own memory limit
 * (D-040), so a PDF that inflates when it is read ends that one source, never the worker.
 */
class PdfExtractor
{
    /**
     * @return array{ok: true, title: string, text: string, pages: list<array{page: int, start: int, end: int}>}|array{ok: false, error: string, max?: int}
     */
    public static function extract(string $path, int $maxPages, int $minCharsPerPage): array
    {
        try {
            // Only the text is read: keeping the images in memory would only cost memory.
            $config = new Config;
            $config->setRetainImageContent(false);
            $document = (new Parser([], $config))->parseFile($path);
            $pages = $document->getPages();
        } catch (Throwable) {
            return ['ok' => false, 'error' => 'read'];
        }

        if (count($pages) > $maxPages) {
            return ['ok' => false, 'error' => 'pages', 'max' => $maxPages];
        }

        $text = '';
        $ranges = [];
        $withText = 0;
        foreach ($pages as $i => $page) {
            $pageText = trim(preg_replace("/[ \t]+/", ' ', (string) $page->getText()) ?? '');
            if (mb_strlen($pageText) >= $minCharsPerPage) {
                $withText++;
            }
            if ($pageText === '') {
                continue;
            }
            $start = mb_strlen($text);
            $text .= $pageText;
            $ranges[] = ['page' => $i + 1, 'start' => $start, 'end' => mb_strlen($text)];
            $text .= "\n\n";
        }

        if ($withText === 0) {
            return ['ok' => false, 'error' => 'notext'];
        }

        return ['ok' => true, 'title' => trim((string) ($document->getDetails()['Title'] ?? '')), 'text' => rtrim($text), 'pages' => $ranges];
    }
}
