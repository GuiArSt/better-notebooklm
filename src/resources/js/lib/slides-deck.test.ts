import { describe, expect, it } from 'vitest';
import { citeFrom, deckProblems, toDeck } from '@/lib/slides-deck';
import { sampleDeck } from '@/slides/fixtures';
import type { Citation } from '@/types/app';

// The server's shape: citations are passage ids (numbers).
const serverItem = (deck = sampleDeck) => ({
    title: deck.title,
    slides: deck.slides.map((s) => ({ ...s, citations: [12, 13] })),
});

describe('the slide deck as the server sends it', () => {
    it('turns numeric passage ids into the "P12" strings the templates take', () => {
        const deck = toDeck(serverItem());

        expect(deck.title).toBe(sampleDeck.title);
        expect(deck.slides[0].citations).toEqual(['P12', 'P13']);
        expect(deck.slides).toHaveLength(sampleDeck.slides.length);
    });

    it('accepts a fixture-sized deck and names the problems of a broken one', () => {
        expect(deckProblems(serverItem())).toEqual([]);
        expect(deckProblems({ title: 'x', slides: [] })).not.toEqual([]);
        expect(deckProblems({ title: 'x', slides: null })).not.toEqual([]);
    });

    it('resolves only ids the server validated', () => {
        const citations = [
            {
                id: 12,
                source_id: 1,
                source_title: 's',
                page: null,
                start: 0,
                end: 1,
                quote: 'q',
            },
        ] as Citation[];
        const cite = citeFrom(citations);

        expect(cite('P12')?.id).toBe(12);
        expect(cite('P99')).toBeUndefined();
        expect(citeFrom(null)('P12')).toBeUndefined();
    });
});
