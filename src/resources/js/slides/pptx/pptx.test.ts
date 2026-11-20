import JSZip from 'jszip'; // pptxgenjs's own dependency, always installed with it
import PptxGenJS from 'pptxgenjs';
import { describe, expect, it } from 'vitest';
import { stressDeck } from '@/slides/fit/stress-fixtures';
import { maxDeck, sampleDeck } from '@/slides/fixtures';
import {
    buildPptx,
    fitFontSize,
    pptxFileName,
    sourcesLine,
} from '@/slides/pptx/deck-to-pptx';
import { TEMPLATES } from '@/slides/types';
import type { Deck } from '@/slides/types';
import type { Citation } from '@/types/app';

const citation = (id: number, title: string): Citation => ({
    id,
    source_id: id,
    source_title: title,
    page: null,
    start: 0,
    end: 1,
    quote: 'q',
});
const known: Record<string, Citation> = {
    P1: citation(1, 'Voyager 1'),
    P2: citation(2, 'Voyager 2'),
    P3: citation(3, 'Voyager 1'),
};
const cite = (id: string) => known[id];

/** The first text a slide shows, the field each template leads with. */
const firstText = (slide: Deck['slides'][number]): string => {
    const f = slide.fields as Record<string, unknown>;

    return String(f.heading ?? f.quote);
};

const xmlEscape = (text: string) =>
    text
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&apos;');

describe('every template maps to pptx', () => {
    for (const [name, deck] of [
        ['maxDeck', maxDeck],
        ['stressDeck', stressDeck],
        ['sampleDeck', sampleDeck],
    ] as [string, Deck][]) {
        it(`${name}: all templates, one slide each, a real zip`, async () => {
            expect(new Set(deck.slides.map((s) => s.template))).toEqual(
                new Set(TEMPLATES),
            );
            const pres = buildPptx(deck, PptxGenJS, { cite });
            const bytes = (await pres.write({
                outputType: 'arraybuffer',
            })) as ArrayBuffer;
            expect(bytes.byteLength).toBeGreaterThan(1000);
            // open the archive as PowerPoint would: the package manifest, then each slide's XML
            const zip = await JSZip.loadAsync(bytes);
            const types = await zip
                .file('[Content_Types].xml')
                ?.async('string');
            expect(types).toContain(
                'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml',
            );
            const slideFiles = Object.keys(zip.files).filter((f) =>
                /^ppt\/slides\/slide\d+\.xml$/.test(f),
            );
            expect(slideFiles).toHaveLength(deck.slides.length);
            for (const [i, slide] of deck.slides.entries()) {
                const xml = await zip
                    .file(`ppt/slides/slide${i + 1}.xml`)
                    ?.async('string');
                expect(xml, `slide ${i + 1} is missing`).toBeDefined();
                expect(xml).toContain('<p:sld');
                expect(xml).toContain(`${i + 1} / ${deck.slides.length}`);
                // the slide carries its own words, not another slide's
                expect(xml).toContain(xmlEscape(firstText(slide).slice(0, 24)));
            }
        });
    }

    it('works without a cite function', () => {
        expect(() => buildPptx(maxDeck, PptxGenJS)).not.toThrow();
    });
});

describe('sourcesLine', () => {
    it('lists the resolved sources, once each, and drops unresolved ids', () => {
        expect(sourcesLine(['P1', 'P9', 'P2', 'P3'], cite)).toBe(
            'Sources: Voyager 1, Voyager 2',
        );
    });

    it('is empty when nothing resolves', () => {
        expect(sourcesLine(['P9'], cite)).toBe('');
        expect(sourcesLine([], cite)).toBe('');
        expect(sourcesLine(['P1'])).toBe('');
    });
});

describe('pptxFileName', () => {
    it('lower-cases and hyphenates the title', () => {
        expect(
            pptxFileName({
                title: 'Voyager 1 and 2: fifty years out',
                slides: [],
            }),
        ).toBe('voyager-1-and-2-fifty-years-out.pptx');
        expect(pptxFileName({ title: '???', slides: [] })).toBe('slides.pptx');
    });
});

describe('fitFontSize', () => {
    it('keeps the full size when the text fits', () => {
        expect(
            fitFontSize(['Voyager 1 and 2'], 9.6, 2.3, 44, { bold: true }),
        ).toBe(44);
    });

    it('steps down for 2.5x text, so the estimate fits the box', () => {
        const long = 'x'.repeat(175);
        const size = fitFontSize([long], 9.6, 2.3, 44, { bold: true });
        const perLine = Math.floor((9.6 * 72) / (size * 0.6));

        expect(size).toBeLessThan(44);
        expect(
            (Math.ceil(175 / perLine) * size * 1.2) / 72,
        ).toBeLessThanOrEqual(2.3);
    });

    it('counts every list item as at least a line, plus its gap', () => {
        const one = fitFontSize(['a'], 4, 1, 16, { paraGapPt: 8 });
        const many = fitFontSize(['a', 'b', 'c', 'd', 'e', 'f'], 4, 1, 16, {
            paraGapPt: 8,
        });

        expect(many).toBeLessThan(one);
    });

    it('never goes below its floor', () => {
        expect(fitFontSize(['x'.repeat(5000)], 1, 0.5, 20)).toBe(8);
        expect(fitFontSize(['x'.repeat(5000)], 1, 0.5, 40)).toBe(16);
    });
});
