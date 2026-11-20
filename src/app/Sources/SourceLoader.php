<?php

namespace App\Sources;

use App\Ai\Exceptions\UserFacingException;
use App\Models\Source;
use Illuminate\Support\Facades\Storage;

/** Fills a source's text (and page ranges) from where it came from: an uploaded PDF or a web page. */
class SourceLoader
{
    public function __construct(private Parsers $parsers, private UrlFetcher $fetcher) {}

    public function load(Source $source): void
    {
        if ($source->content !== null && $source->content !== '') {
            return;
        }

        $parsed = match ($source->kind) {
            'pdf' => $this->parsers->pdf(
                Storage::disk('local')->get((string) $source->file_path) ?? throw new UserFacingException('The uploaded file is missing.'),
                $source->title,
            ),
            'web' => $this->web((string) $source->origin_url),
            default => throw new UserFacingException('This source has no text.'),
        };

        // The same cap as pasted text: a web page or a PDF may not be a way around it.
        $max = (int) config('retrieval.max_source_chars');
        if (mb_strlen($parsed->text) > $max) {
            throw new UserFacingException(LargeSource::refusal('This source'));
        }

        $source->update(['content' => $parsed->text, 'chars' => mb_strlen($parsed->text), 'pages' => $parsed->pages, 'title' => $this->betterTitle($source, $parsed)]);
    }

    /** A web source is first titled by its host; once the page is read, the page's own title is better (found live). */
    private function betterTitle(Source $source, ParsedSource $parsed): string
    {
        $host = (string) parse_url((string) $source->origin_url, PHP_URL_HOST);
        $isPlaceholder = $source->title === '' || ($source->kind === 'web' && in_array($source->title, [$host, preg_replace('/^www\./', '', $host)], true));

        return ($isPlaceholder && trim($parsed->title) !== '') ? mb_substr(trim($parsed->title), 0, 200) : $source->title;
    }

    private function web(string $url): ParsedSource
    {
        $page = $this->fetcher->fetchPage($url);

        $parsed = $page->contentType === 'text/plain'
            ? $this->parsers->plain($page->body, (string) parse_url($url, PHP_URL_HOST))
            : $this->parsers->html($page->body, $url);

        // Almost no text (a page that builds itself with JavaScript, a cookie wall, a login page): say so, never store a near-empty source as ready.
        if (mb_strlen(trim($parsed->text)) < (int) config('sources.web.min_chars')) {
            throw new UserFacingException(Parsers::THIN_PAGE);
        }

        return $parsed;
    }
}
