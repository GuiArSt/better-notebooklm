import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import { stressDeck, stress } from '@/slides/fit/stress-fixtures';
import { SlideView } from '@/slides/slide';
import { chars, TARGETS } from '@/slides/types';
import type { Slide } from '@/slides/types';

const render = (slide: Slide, index: number) =>
    renderToStaticMarkup(
        <SlideView
            slide={slide}
            context={{
                index,
                total: stressDeck.slides.length,
                deckTitle: stressDeck.title,
            }}
        />,
    );

const texts = (slide: Slide): string[] => {
    switch (slide.template) {
        case 'bullets':
            return [slide.fields.heading, ...slide.fields.items];
        case 'two_columns': {
            const { heading, left, right } = slide.fields;

            return [
                heading,
                left.label,
                ...left.items,
                right.label,
                ...right.items,
            ];
        }
        case 'steps':
            return [
                slide.fields.heading,
                ...slide.fields.steps.flatMap((s) => [s.label, s.text]),
            ];
        default:
            return Object.values(slide.fields) as string[];
    }
};

const escaped = (text: string) =>
    text.replaceAll("'", '&#x27;').replaceAll('"', '&quot;');

describe('stressDeck', () => {
    it('has every field at exactly 2.5x its target', () => {
        const [title, section, bullets, cols, big, quote, steps] =
            stressDeck.slides;
        expect(chars(stressDeck.title)).toBe(stress(TARGETS.deck.title));
        const len = (slide: Slide) => texts(slide).map(chars);
        expect(len(title)).toEqual([175, 300]);
        expect(len(section)).toEqual([150, 100]);
        expect(len(bullets)).toEqual([150, ...Array(5).fill(275)]);
        expect(len(cols)).toEqual([
            150,
            75,
            ...Array(4).fill(200),
            75,
            ...Array(4).fill(200),
        ]);
        expect(len(big)).toEqual([150, 30, 150, 400]);
        expect(len(quote)).toEqual([550, 200]);
        expect(len(steps)).toEqual([150, ...Array(5).fill([75, 225]).flat()]);
    });

    it("is above today's hard maximums, on purpose", () => {
        expect(chars(stressDeck.title)).toBeGreaterThan(104);
    });
});

describe('every template draws the stress deck uncut', () => {
    stressDeck.slides.forEach((slide, index) => {
        it(slide.template, () => {
            const out = render(slide, index);
            for (const text of texts(slide)) {
                for (const word of text.split(' ')) {
                    expect(out).toContain(escaped(word));
                }
                expect(out).toContain(escaped(text));
            }
            expect(out).not.toContain('…');
            expect(out).not.toContain('line-clamp');
            expect(out).not.toContain('text-overflow');
            // no layout here, so the hook leaves the text at full size
            expect(out).not.toMatch(/--sl-fit:\s*0/);
        });
    });
});
