/**
 * The stress deck: every field at 2.5x its TARGET, every list at its maximum size, real prose.
 * Every field sits exactly at its hard maximum (LIMITS = 250% of the target, D-054), so it is a valid deck: slides.test.tsx runs it
 * through checkDeck, and the fit tests here check its lengths and that every template shows it uncut.
 */
import { exactly } from '@/slides/fixtures';
import { TARGETS } from '@/slides/types';
import type { Deck } from '@/slides/types';

export const STRESS_FACTOR = 2.5;

/** A target at the stress factor, whole code points (the prose is plain ASCII, so characters and code points agree). */
export const stress = (target: number): number =>
    Math.floor(target * STRESS_FACTOR);

const B = TARGETS.bullets;
const C = TARGETS.two_columns;
const S = TARGETS.steps;
const N = TARGETS.big_number;

export const stressDeck: Deck = {
    title: exactly(stress(TARGETS.deck.title), 3),
    slides: [
        {
            template: 'title',
            fields: {
                heading: exactly(stress(TARGETS.title.heading), 0),
                subheading: exactly(stress(TARGETS.title.subheading), 20),
            },
            citations: [],
        },
        {
            template: 'section',
            fields: {
                heading: exactly(stress(TARGETS.section.heading), 40),
                kicker: exactly(stress(TARGETS.section.kicker), 11),
            },
            citations: [],
        },
        {
            template: 'bullets',
            fields: {
                heading: exactly(stress(B.heading), 5),
                items: Array.from({ length: B.maxItems }, (_, i) =>
                    exactly(stress(B.item), i * 17),
                ),
            },
            citations: ['P1', 'P2', 'P3'],
        },
        {
            template: 'two_columns',
            fields: {
                heading: exactly(stress(C.heading), 9),
                left: {
                    label: exactly(stress(C.label), 2),
                    items: Array.from({ length: C.maxItems }, (_, i) =>
                        exactly(stress(C.item), i * 13),
                    ),
                },
                right: {
                    label: exactly(stress(C.label), 30),
                    items: Array.from({ length: C.maxItems }, (_, i) =>
                        exactly(stress(C.item), 50 + i * 11),
                    ),
                },
            },
            citations: ['P2'],
        },
        {
            template: 'big_number',
            fields: {
                heading: exactly(stress(N.heading), 14),
                number: exactly(stress(N.number), 4),
                label: exactly(stress(N.label), 60),
                context: exactly(stress(N.context), 90),
            },
            citations: ['P4'],
        },
        {
            template: 'quote',
            fields: {
                quote: exactly(stress(TARGETS.quote.quote), 0),
                attribution: exactly(stress(TARGETS.quote.attribution), 120),
            },
            citations: ['P5'],
        },
        {
            template: 'steps',
            fields: {
                heading: exactly(stress(S.heading), 22),
                steps: Array.from({ length: S.maxSteps }, (_, i) => ({
                    label: exactly(stress(S.label), i * 7),
                    text: exactly(stress(S.text), 30 + i * 19),
                })),
            },
            citations: ['P1', 'P6'],
        },
    ],
};
