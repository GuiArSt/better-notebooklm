/**
 * Fixture decks for the tests and the preview page: every template, every field at its maximum length,
 * every list at its maximum size. If these fit, any valid deck fits.
 */
import { TARGETS } from '@/slides/types';
import type { Deck } from '@/slides/types';

const PROSE =
    'Voyager 1 crossed the heliopause in August 2012 and became the first human-made object to reach interstellar space, ' +
    'still sending data home on a twenty-two watt transmitter while its plutonium supply slowly declines year after year. ';

/** Real words, cut to exactly `n` characters, never ending in a space. */
export function exactly(n: number, seed = 0): string {
    let text = '';
    while (text.length < n + seed) {
        text += PROSE;
    }
    let start = seed;
    while (text[start] === ' ') {
        start++;
    }
    while (text.length < start + n) {
        text += PROSE;
    }
    const out = text.slice(start, start + n);

    return out.endsWith(' ') ? `${out.slice(0, -1)}.` : out;
}

const B = TARGETS.bullets;
const C = TARGETS.two_columns;
const S = TARGETS.steps;

/** All seven templates at their maximum lengths, in a valid order (title first, section not last). */
export const maxDeck: Deck = {
    title: exactly(TARGETS.deck.title, 3),
    slides: [
        {
            template: 'title',
            fields: {
                heading: exactly(TARGETS.title.heading, 0),
                subheading: exactly(TARGETS.title.subheading, 20),
            },
            citations: [],
        },
        {
            template: 'section',
            fields: {
                heading: exactly(TARGETS.section.heading, 40),
                kicker: exactly(TARGETS.section.kicker, 11),
            },
            citations: [],
        },
        {
            template: 'bullets',
            fields: {
                heading: exactly(B.heading, 5),
                items: Array.from({ length: B.maxItems }, (_, i) =>
                    exactly(B.item, i * 17),
                ),
            },
            citations: ['P1', 'P2', 'P3'],
        },
        {
            template: 'two_columns',
            fields: {
                heading: exactly(C.heading, 9),
                left: {
                    label: exactly(C.label, 2),
                    items: Array.from({ length: C.maxItems }, (_, i) =>
                        exactly(C.item, i * 13),
                    ),
                },
                right: {
                    label: exactly(C.label, 30),
                    items: Array.from({ length: C.maxItems }, (_, i) =>
                        exactly(C.item, 50 + i * 11),
                    ),
                },
            },
            citations: ['P2'],
        },
        {
            template: 'big_number',
            fields: {
                heading: exactly(TARGETS.big_number.heading, 14),
                number: '1,234,567 km',
                label: exactly(TARGETS.big_number.label, 60),
                context: exactly(TARGETS.big_number.context, 90),
            },
            citations: ['P4'],
        },
        {
            template: 'quote',
            fields: {
                quote: exactly(TARGETS.quote.quote, 0),
                attribution: exactly(TARGETS.quote.attribution, 120),
            },
            citations: ['P5'],
        },
        {
            template: 'steps',
            fields: {
                heading: exactly(S.heading, 22),
                steps: Array.from({ length: S.maxSteps }, (_, i) => ({
                    label: exactly(S.label, i * 7),
                    text: exactly(S.text, 30 + i * 19),
                })),
            },
            citations: ['P1', 'P6'],
        },
    ],
};

/** A deck as a model would write it: shorter text, the smaller list sizes, a repeated template. */
export const sampleDeck: Deck = {
    title: 'Voyager 1 and 2: fifty years out',
    slides: [
        {
            template: 'title',
            fields: {
                heading: 'Voyager 1 and 2',
                subheading:
                    'Two probes, four planets and the edge of the Sun’s reach, from two web pages.',
            },
            citations: [],
        },
        {
            template: 'big_number',
            fields: {
                heading: 'The farthest thing we have built',
                number: '24.9 bn km',
                label: 'Voyager 1 from Earth',
                context: 'Light from the probe takes almost a day to reach us.',
            },
            citations: ['P1'],
        },
        {
            template: 'section',
            fields: { heading: 'The Grand Tour', kicker: 'Part one' },
            citations: [],
        },
        {
            template: 'bullets',
            fields: {
                heading: 'What the flybys found',
                items: [
                    'Active volcanoes on Io, the first seen beyond Earth.',
                    'A thick, hazy atmosphere around Titan.',
                    'Thin rings around Uranus and Neptune.',
                ],
            },
            citations: ['P2', 'P3'],
        },
        {
            template: 'two_columns',
            fields: {
                heading: 'Two routes',
                left: {
                    label: 'Voyager 1',
                    items: ['Jupiter, Saturn', 'Titan close-up'],
                },
                right: {
                    label: 'Voyager 2',
                    items: [
                        'All four giants',
                        'Only visit to Uranus and Neptune',
                    ],
                },
            },
            citations: ['P2'],
        },
        {
            template: 'steps',
            fields: {
                heading: 'How a signal comes home',
                steps: [
                    {
                        label: 'Transmit',
                        text: '22 watts from the high-gain antenna.',
                    },
                    {
                        label: 'Travel',
                        text: 'About 22 hours at the speed of light.',
                    },
                    {
                        label: 'Receive',
                        text: 'The Deep Space Network’s 70 m dishes.',
                    },
                ],
            },
            citations: ['P4'],
        },
        {
            template: 'quote',
            fields: {
                quote: 'Each probe carries a golden record of sounds and images from Earth, in case anyone finds it.',
                attribution: 'The Voyager 2 web page (a sample quotation)',
            },
            citations: ['P5'],
        },
    ],
};
