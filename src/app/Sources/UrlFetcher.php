<?php

namespace App\Sources;

use App\Ai\Exceptions\UserFacingException;
use JeffersonGoncalves\SsrfGuard\Exceptions\BlockedHostException;
use JeffersonGoncalves\SsrfGuard\SsrfGuard;

/**
 * Fetches a web page for a source. The guard resolves DNS, refuses private, reserved, loopback and link-local
 * addresses, pins the connection to the checked IP, and re-checks every redirect hop. On top of that:
 * ports 80 and 443 only (checked on every hop), 3 redirects, 10 seconds, 5 MB (stopped while downloading),
 * HTML or plain text only, and no proxy from the environment (a proxy resolves DNS itself and defeats the pin).
 */
class UrlFetcher extends SsrfGuard
{
    /** Called by the guard for the first request and for every redirect hop. */
    public function resolveEntries(string $url): ?array
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $port = $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80);
        if (! in_array($port, config('sources.web.allowed_ports'), true)) {
            return null;
        }

        return parent::resolveEntries($url);
    }

    /**
     * @throws BlockedHostException
     * @throws UserFacingException when the page is too large (compressed or inflated), the wrong type, or not a success
     */
    public function fetchPage(string $url): FetchedPage
    {
        $max = (int) config('sources.web.max_bytes');

        $response = $this->safeGet($url, [
            'headers' => ['Accept' => 'text/html, text/plain;q=0.9', 'Accept-Encoding' => 'gzip, deflate', 'User-Agent' => 'BetterNotebookLM/1.0 (source import)'],
            'proxy' => '',
            // Guzzle must not inflate for us: a 100 KB gzip page can be 100 MB of text. We inflate below, under the limit.
            'decode_content' => false,
            // Abort the download as soon as the bytes on the wire pass the limit, instead of buffering it all first.
            'progress' => function ($total, $downloaded) use ($max) {
                if ($downloaded > $max) {
                    throw new UserFacingException($this->tooLarge($max));
                }
            },
        ]);

        if (! $response->successful()) {
            throw new UserFacingException("The page answered with status {$response->status()}.");
        }

        $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        if (! in_array($type, config('sources.web.content_types'), true)) {
            throw new UserFacingException('Only web pages and plain text can be added ('.($type ?: 'unknown type').' was returned).');
        }

        return new FetchedPage($this->inflate($response->body(), strtolower(trim((string) $response->header('Content-Encoding'))), $max), $type);
    }

    /**
     * Inflate a compressed body one small step at a time and stop the moment the text passes the limit, so a
     * decompression bomb can never be held in memory. (One step can grow by about 1,000 times: steps are 1 KB.)
     */
    private function inflate(string $body, string $encoding, int $max): string
    {
        if ($encoding === '' || $encoding === 'identity') {
            return $body;
        }

        $mode = match ($encoding) {
            'gzip', 'x-gzip' => ZLIB_ENCODING_GZIP,
            'deflate' => ZLIB_ENCODING_DEFLATE,
            default => throw new UserFacingException('This page uses a compression format that is not supported.'),
        };

        $context = inflate_init($mode) ?: throw new UserFacingException('This page could not be read.');
        $out = '';
        foreach (str_split($body, 1024) as $chunk) {
            $piece = @inflate_add($context, $chunk);
            if ($piece === false) {
                throw new UserFacingException('This page could not be read.');
            }
            $out .= $piece;
            if (strlen($out) > $max) {
                throw new UserFacingException($this->tooLarge($max));
            }
        }

        return $out;
    }

    private function tooLarge(int $max): string
    {
        return 'The page is larger than the limit of '.intdiv($max, 1024 * 1024).' MB.';
    }

    /**
     * The package also sets CURLOPT_FOLLOWLOCATION, which this Guzzle refuses ("use allow_redirects instead").
     * allow_redirects stays false, so redirects are still followed only by the guard, hop by hop. Found by a real fetch.
     */
    protected function buildOptions(array $resolve, array $callerOptions): array
    {
        $options = parent::buildOptions($resolve, $callerOptions);
        unset($options['curl'][CURLOPT_FOLLOWLOCATION]);

        return $options;
    }

    protected function timeout(): int
    {
        return (int) config('sources.web.timeout');
    }

    protected function maxRedirects(): int
    {
        return (int) config('sources.web.max_redirects');
    }

    protected function maxResponseSize(): int
    {
        return (int) config('sources.web.max_bytes');
    }
}
