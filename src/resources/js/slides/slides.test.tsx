import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import { maxDeck, sampleDeck } from '@/slides/fixtures';
import { SlideView } from '@/slides/slide';
import type { SlideContext } from '@/slides/slide-frame';
import { numberSize } from '@/slides/templates/big-number-slide';
import {
    STRESS_FACTOR,
    stress,
    stressDeck,
} from '@/slides/fit/stress-fixtures';
import {
    chars,
    checkDeck,
    hardMax,
    LIMITS,
    TARGETS,
    TEMPLATES,
} from '@/slides/types';
import type { Deck, Slide, TemplateName } from '@/slides/types';
import type { Citation } from '@/types/app';

const citation = (n: number): Citation => ({
    id: n,
    source_id: 1,
    source_title: 'Voyager 1',
    page: null,
    start: 0,
    end: 10,
    quote: 'a passage',
});

const known: Record<string, Citation> = { P1: citation(1), P2: citation(2) };

const ctx = (index: number, deck: Deck = maxDeck): SlideContext => ({
    index,
    total: deck.slides.length,
    deckTitle: deck.title,
    cite: (id) => known[id],
});

const html = (slide: Slide, index: number) =>
    renderToStaticMarkup(<SlideView slide={slide} context={ctx(index)} />);

/** React escapes &, <, > and quotes; the fixture text has none of the first three. */
const escaped = (text: string) =>
    text.replaceAll("'", '&#x27;').replaceAll('"', '&quot;');

const at = (template: TemplateName) => {
    const index = maxDeck.slides.findIndex((s) => s.template === template);

    return {
        slide: maxDeck.slides[index],
        index,
        out: html(maxDeck.slides[index], index),
    };
};

/** Every string in a slide's fields, depth first. */
const texts = (value: unknown): string[] =>
    typeof value === 'string'
        ? [value]
        : Array.isArray(value)
          ? value.flatMap(texts)
          : value && typeof value === 'object'
            ? Object.values(value).flatMap(texts)
            : [];

describe('the maximum-length fixture deck', () => {
    it('is valid and holds all seven templates', () => {
        expect(checkDeck(maxDeck)).toEqual([]);
        expect(maxDeck.slides.map((s) => s.template).sort()).toEqual(
            [...TEMPLATES].sort(),
        );
    });

    it('fills every field to exactly its limit, and every list to its maximum size', () => {
        const [title, section, bullets, cols, big, quote, steps] =
            maxDeck.slides;

        expect(maxDeck.title).toHaveLength(TARGETS.deck.title);
        expect(title.template === 'title' && title.fields.heading).toHaveLength(
            TARGETS.title.heading,
        );
        expect(
            title.template === 'title' && title.fields.subheading,
        ).toHaveLength(TARGETS.title.subheading);
        expect(
            section.template === 'section' && section.fields.kicker,
        ).toHaveLength(TARGETS.section.kicker);
        if (
            bullets.template !== 'bullets' ||
            cols.template !== 'two_columns' ||
            steps.template !== 'steps'
        ) {
            throw new Error('fixture order changed');
        }
        expect(bullets.fields.items).toHaveLength(TARGETS.bullets.maxItems);
        bullets.fields.items.forEach((t) =>
            expect(t).toHaveLength(TARGETS.bullets.item),
        );
        expect(cols.fields.left.items).toHaveLength(
            TARGETS.two_columns.maxItems,
        );
        cols.fields.right.items.forEach((t) =>
            expect(t).toHaveLength(TARGETS.two_columns.item),
        );
        expect(big.template === 'big_number' && big.fields.number).toHaveLength(
            TARGETS.big_number.number,
        );
        expect(
            big.template === 'big_number' && big.fields.context,
        ).toHaveLength(TARGETS.big_number.context);
        expect(quote.template === 'quote' && quote.fields.quote).toHaveLength(
            TARGETS.quote.quote,
        );
        expect(steps.fields.steps).toHaveLength(TARGETS.steps.maxSteps);
        steps.fields.steps.forEach((s) => {
            expect(s.label).toHaveLength(TARGETS.steps.label);
            expect(s.text).toHaveLength(TARGETS.steps.text);
        });
    });

    it('the sample deck, as a model would write it, is valid too', () => {
        expect(checkDeck(sampleDeck)).toEqual([]);
    });
});

describe.each(TEMPLATES)('the %s template at maximum lengths', (template) => {
    it('draws every word of every field, uncut, in a 16:9 frame of its own template', () => {
        const { slide, out } = at(template);

        expect(out).toContain(`data-template="${template}"`);
        expect(out).toContain(`sl--${template}`);
        for (const text of texts(slide.fields)) {
            expect(out).toContain(escaped(text));
        }
        // the slide's own content is never shortened (the chip's hover popover has its own clamp)
        expect(out.split('<footer')[0]).not.toMatch(
            /…|line-clamp|text-overflow/,
        );
    });

    it('numbers itself in the deck and repeats the deck title in the footer, except on the opening slide', () => {
        const { index, out } = at(template);

        expect(out).toContain(`Slide ${index + 1} of ${maxDeck.slides.length}`);
        expect(out).toContain(`${index + 1} / ${maxDeck.slides.length}`);
        if (index === 0) {
            expect(out).not.toContain(escaped(maxDeck.title));
        } else {
            expect(out).toContain(escaped(maxDeck.title));
        }
    });

    it('draws a chip only for the citations the viewer can resolve', () => {
        const { slide, out } = at(template);
        const resolvable = slide.citations.filter((id) => id in known);

        expect(out.match(/data-testid="citation"/g) ?? []).toHaveLength(
            resolvable.length,
        );
        if (resolvable.length === 0) {
            expect(out).not.toContain('slide-citations');
        }
    });
});

describe('the template details', () => {
    it('title and section carry the constellation; section is inverted', () => {
        expect(at('title').out).toContain('sl-constellation');
        expect(at('section').out).toContain('sl-constellation');
        expect(at('section').out).toContain('is-inverted');
        expect(at('bullets').out).not.toContain('sl-constellation');
    });

    it('the big number steps down in size as it grows to twelve characters', () => {
        expect(numberSize('42%')).toBe('l');
        expect(numberSize('24.9 bn')).toBe('m');
        expect(numberSize('1,234,567 km')).toBe('s');
        expect(at('big_number').out).toContain('data-size="s"');
    });

    it('steps say how many there are, so three steps can be set larger than five', () => {
        expect(at('steps').out).toContain('data-count="5"');
        const three = sampleDeck.slides.find((s) => s.template === 'steps')!;

        expect(
            renderToStaticMarkup(
                <SlideView slide={three} context={ctx(5, sampleDeck)} />,
            ),
        ).toContain('data-count="3"');
    });

    it('draws no chips at all without a resolver', () => {
        const slide = at('bullets').slide;
        const out = renderToStaticMarkup(
            <SlideView
                slide={slide}
                context={{ index: 2, total: 7, deckTitle: 'T' }}
            />,
        );

        expect(out).not.toContain('data-testid="citation"');
    });
});

describe('the hard maximum is 250% of the target (D-054)', () => {
    it('makes the 2.5x stress deck a valid deck, field for field', () => {
        expect(checkDeck(stressDeck)).toEqual([]);
    });

    it('is exactly the stress factor in whole characters', () => {
        expect(STRESS_FACTOR).toBe(2.5);
        for (const target of [12, 30, 40, 60, 70, 80, 90, 110, 120, 160, 220]) {
            expect(hardMax(target)).toBe(stress(target));
        }
    });
});

describe('checkDeck, the client copy of the contract', () => {
    const clone = (): Deck => structuredClone(maxDeck);

    it('accepts a field AT its hard maximum (250% of the target) and names one a character over it', () => {
        const deck = clone();
        const bullets = deck.slides[2];
        if (bullets.template !== 'bullets') {
            throw new Error('fixture order changed');
        }
        expect(hardMax(TARGETS.bullets.item)).toBe(275);
        bullets.fields.items[0] = 'x'.repeat(hardMax(TARGETS.bullets.item));
        expect(checkDeck(deck)).toEqual([]);

        bullets.fields.items[0] += 'x';
        expect(checkDeck(deck)).toEqual([
            'slides[2].items[0]: 276 characters, limit 275',
        ]);
    });

    it('refuses an unknown template, a missing field and a list of the wrong size', () => {
        const deck = clone();
        (deck.slides[3] as { template: string }).template = 'chart';
        const steps = deck.slides[6];
        if (steps.template === 'steps') {
            steps.fields.steps = steps.fields.steps.slice(0, 2);
        }
        const quote = deck.slides[5];
        if (quote.template === 'quote') {
            quote.fields.attribution = '';
        }

        expect(checkDeck(deck)).toEqual([
            'slides[3].template: unknown "chart"',
            'slides[5].attribution: missing',
            'slides[6].steps: needs 3 to 5',
        ]);
    });

    it('wants a title first, no section last, and 6 to 12 slides', () => {
        const deck = clone();
        deck.slides = [deck.slides[1], deck.slides[2], deck.slides[1]];

        expect(checkDeck(deck)).toEqual([
            'slides: 3, needs 6 to 12',
            'slides[0]: the first slide must be "title"',
            'slides[2]: "section" cannot be the last slide',
        ]);
    });

    it('counts characters as code points, like PHP (SL-2): an emoji is one character, not two', () => {
        expect(chars('🛰️ab')).toBe(4); // U+1F6F0, U+FE0F, a, b
        expect('🛰'.length).toBe(2);
        expect(chars('🛰')).toBe(1);

        const deck = clone();
        const title = deck.slides[0];
        if (title.template !== 'title') {
            throw new Error('fixture order changed');
        }
        // 174 letters and one astral emoji: 175 code points (the hard maximum), though .length says 176
        title.fields.heading = 'a'.repeat(LIMITS.title.heading - 1) + '🛰';
        expect(title.fields.heading.length).toBe(LIMITS.title.heading + 1);
        expect(checkDeck(deck)).toEqual([]);

        title.fields.heading += '🛰';
        expect(checkDeck(deck)).toEqual([
            `slides[0].heading: ${LIMITS.title.heading + 1} characters, limit ${LIMITS.title.heading}`,
        ]);
    });

    it('returns errors instead of throwing on a malformed deck (SL-3)', () => {
        const noFields = clone() as unknown as {
            slides: Record<string, unknown>[];
        };
        delete noFields.slides[2].fields;

        expect(checkDeck(noFields)).toEqual(['slides[2].fields: missing']);
        expect(checkDeck({ title: 'T', slides: 'many' })).toEqual([
            'slides: missing or not a list',
        ]);
        expect(checkDeck({ title: 'T' })).toEqual([
            'slides: missing or not a list',
        ]);
        expect(checkDeck(null)).toEqual(['deck: not an object']);
        expect(checkDeck([])).toEqual(['deck: not an object']);

        const holes = clone() as unknown as { slides: unknown[] };
        holes.slides[3] = null;
        holes.slides[4] = 'big_number';
        expect(checkDeck(holes)).toEqual([
            'slides[3]: not an object',
            'slides[4]: not an object',
        ]);

        const odd = clone() as unknown as { slides: Record<string, unknown>[] };
        odd.slides[2].fields = { heading: 42, items: 'not a list' };
        odd.slides[6].fields = { heading: 'Steps', steps: [null, 1, 'x'] };
        expect(checkDeck(odd)).toEqual([
            'slides[2].heading: missing',
            'slides[2].items: needs 3 to 5',
            'slides[6].steps[0].label: missing',
            'slides[6].steps[0].text: missing',
            'slides[6].steps[1].label: missing',
            'slides[6].steps[1].text: missing',
            'slides[6].steps[2].label: missing',
            'slides[6].steps[2].text: missing',
        ]);
    });
});
