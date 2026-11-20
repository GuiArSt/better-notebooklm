import { readFileSync } from 'node:fs';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import { CitationChip } from '@/components/notebook/citation';

const citation = {
    id: 1,
    source_id: 2,
    source_title: 'Orion',
    page: null,
    start: 0,
    end: 5,
    quote: 'the quote',
};

describe('the citation chip’s quote', () => {
    it('is closed until the chip is hovered or reached by keyboard', () => {
        const html = renderToStaticMarkup(
            <CitationChip
                index={0}
                citation={citation}
                onOpen={() => undefined}
            />,
        );

        expect(html).toMatch(/data-testid="citation-quote"/);
        expect(html).toMatch(
            /class="[^"]*\bhidden\b[^"]*"[^>]*><span class="block font-semibold"/,
        );
    });

    it('closes on a click, on Escape and on a press outside, and does not stay open on focus-within (visual pass 3)', () => {
        const source = readFileSync(
            new URL('./citation.tsx', import.meta.url),
            'utf8',
        );

        expect(source).not.toContain('group-focus-within');
        expect(source).toContain("e.key === 'Escape'");
        expect(source).toContain("'pointerdown'");
        expect(source).toContain('e.currentTarget.blur()');
        expect(source).toContain(':focus-visible');
    });
});
