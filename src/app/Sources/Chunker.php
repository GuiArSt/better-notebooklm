<?php

namespace App\Sources;

/**
 * Cuts a text into passages of about `chunk_chars`, on paragraph boundaries where it can.
 * Every passage keeps its character offsets into the original, so a citation can point back to the exact span.
 */
class Chunker
{
    /** @return list<array{start: int, end: int, text: string}> */
    public function chunk(string $text, ?int $target = null): array
    {
        $target ??= (int) config('retrieval.chunk_chars');
        $parts = preg_split('/(\n[ \t]*\n+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];

        // [start, end] of each non-blank paragraph, in characters.
        $paragraphs = [];
        $pos = 0;
        foreach ($parts as $i => $part) {
            $len = mb_strlen($part);
            if ($i % 2 === 0 && trim($part) !== '') {
                $lead = $len - mb_strlen(ltrim($part));
                $paragraphs[] = [$pos + $lead, $pos + mb_strlen(rtrim($part))];
            }
            $pos += $len;
        }

        $spans = [];
        $current = null;
        foreach ($paragraphs as [$start, $end]) {
            foreach ($this->windows($text, $start, $end, $target) as [$s, $e]) {
                if ($current !== null && $e - $current[0] <= $target) {
                    $current[1] = $e;

                    continue;
                }
                if ($current !== null) {
                    $spans[] = $current;
                }
                $current = [$s, $e];
            }
        }
        if ($current !== null) {
            $spans[] = $current;
        }

        return array_map(fn (array $s) => ['start' => $s[0], 'end' => $s[1], 'text' => mb_substr($text, $s[0], $s[1] - $s[0])], $spans);
    }

    /**
     * Chunk a text that came in pages: every passage stays inside one page and carries its number.
     *
     * @param  list<array{page: int, start: int, end: int}>  $pages
     * @return list<array{start: int, end: int, text: string, page: int}>
     */
    public function chunkPages(string $text, array $pages, ?int $target = null): array
    {
        $out = [];
        foreach ($pages as $page) {
            $slice = mb_substr($text, $page['start'], $page['end'] - $page['start']);
            foreach ($this->chunk($slice, $target) as $c) {
                $out[] = [
                    'start' => $page['start'] + $c['start'],
                    'end' => $page['start'] + $c['end'],
                    'text' => $c['text'],
                    'page' => $page['page'],
                ];
            }
        }

        return $out;
    }

    /**
     * A paragraph longer than the target is cut into windows, at the last space before the limit.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function windows(string $text, int $start, int $end, int $target): array
    {
        if ($end - $start <= $target) {
            return [[$start, $end]];
        }

        $windows = [];
        while ($end - $start > $target) {
            $slice = mb_substr($text, $start, $target);
            $cut = mb_strrpos($slice, ' ');
            $len = ($cut !== false && $cut > $target / 2) ? $cut : $target;
            $windows[] = [$start, $start + $len];
            $start += $len;
            while ($start < $end && mb_substr($text, $start, 1) === ' ') {
                $start++;
            }
        }
        $windows[] = [$start, $end];

        return $windows;
    }
}
