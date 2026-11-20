/**
 * The slide-deck contract (D-034), the TypeScript copy. ⛏️ Atlas owns the PHP copy; the two are kept identical.
 * The model never writes layout: it picks one of these templates and fills its fields, within these limits.
 */

export const TEMPLATES = [
    'title',
    'section',
    'bullets',
    'two_columns',
    'big_number',
    'quote',
    'steps',
] as const;

export type TemplateName = (typeof TEMPLATES)[number];

export type TitleFields = { heading: string; subheading: string };
export type SectionFields = { heading: string; kicker: string };
export type BulletsFields = { heading: string; items: string[] };
export type Column = { label: string; items: string[] };
export type TwoColumnsFields = { heading: string; left: Column; right: Column };
export type BigNumberFields = {
    heading: string;
    number: string;
    label: string;
    context: string;
};
export type QuoteFields = { quote: string; attribution: string };
export type Step = { label: string; text: string };
export type StepsFields = { heading: string; steps: Step[] };

type Of<T extends TemplateName, F> = {
    template: T;
    fields: F;
    /** Passage ids such as "P12"; only ids the server validated reach the client. */
    citations: string[];
};

export type Slide =
    | Of<'title', TitleFields>
    | Of<'section', SectionFields>
    | Of<'bullets', BulletsFields>
    | Of<'two_columns', TwoColumnsFields>
    | Of<'big_number', BigNumberFields>
    | Of<'quote', QuoteFields>
    | Of<'steps', StepsFields>;

export type SlideOf<T extends TemplateName> = Extract<Slide, { template: T }>;

export type Deck = { title: string; slides: Slide[] };

/**
 * The TARGETS and the list sizes. A target is what the prompt asks the model for (and what the layouts are drawn for); the model cannot count
 * characters, so checkDeck refuses only beyond the HARD maximum, LIMITS below (D-054). A character is a Unicode code point, as PHP's mb_strlen
 * counts it: an emoji is 1, not the 2 UTF-16 units of JavaScript's `.length`. Use `chars()` to count.
 */
export const TARGETS = {
    deck: { title: 80, minSlides: 6, maxSlides: 12 },
    title: { heading: 70, subheading: 120 },
    section: { heading: 60, kicker: 40 },
    bullets: { heading: 60, item: 110, minItems: 3, maxItems: 5 },
    two_columns: { heading: 60, label: 30, item: 80, minItems: 2, maxItems: 4 },
    big_number: { heading: 60, number: 12, label: 60, context: 160 },
    quote: { quote: 220, attribution: 80 },
    steps: { heading: 60, label: 30, text: 90, minSteps: 3, maxSteps: 5 },
} as const;

/** 250% of a target, whole characters, integer arithmetic: the same number as PHP's SlideContract::hardMax. Inclusive: a text AT it is accepted. */
export const hardMax = (target: number): number =>
    Math.floor((target * 250) / 100);

/** The HARD maximums checkDeck enforces (and the sizes of the lists, which are not widened). Identical to the PHP contract; a test keeps them so. */
export const LIMITS = {
    deck: { ...TARGETS.deck, title: hardMax(TARGETS.deck.title) },
    title: {
        heading: hardMax(TARGETS.title.heading),
        subheading: hardMax(TARGETS.title.subheading),
    },
    section: {
        heading: hardMax(TARGETS.section.heading),
        kicker: hardMax(TARGETS.section.kicker),
    },
    bullets: {
        ...TARGETS.bullets,
        heading: hardMax(TARGETS.bullets.heading),
        item: hardMax(TARGETS.bullets.item),
    },
    two_columns: {
        ...TARGETS.two_columns,
        heading: hardMax(TARGETS.two_columns.heading),
        label: hardMax(TARGETS.two_columns.label),
        item: hardMax(TARGETS.two_columns.item),
    },
    big_number: {
        heading: hardMax(TARGETS.big_number.heading),
        number: hardMax(TARGETS.big_number.number),
        label: hardMax(TARGETS.big_number.label),
        context: hardMax(TARGETS.big_number.context),
    },
    quote: {
        quote: hardMax(TARGETS.quote.quote),
        attribution: hardMax(TARGETS.quote.attribution),
    },
    steps: {
        ...TARGETS.steps,
        heading: hardMax(TARGETS.steps.heading),
        label: hardMax(TARGETS.steps.label),
        text: hardMax(TARGETS.steps.text),
    },
} as const;

/** Length in Unicode code points, the contract's unit (the same as PHP's mb_strlen). */
export function chars(text: string): number {
    // UTF-16 units minus surrogate pairs: each pair is one code point. Deliberately not grapheme clusters,
    // because PHP's mb_strlen counts code points too.
    return (
        text.length -
        (text.match(/[\uD800-\uDBFF][\uDC00-\uDFFF]/g)?.length ?? 0)
    );
}

/**
 * The contract's rules, checked on the client. The server is the real gate (it refuses and repairs);
 * this mirror lets the viewer and the tests say exactly what is wrong. Returns one message per problem.
 */
export function checkDeck(input: unknown): string[] {
    const errors: string[] = [];
    if (!isObject(input)) {
        return ['deck: not an object'];
    }
    const deck = input as Deck;
    const len = (where: string, value: unknown, max: number) => {
        if (typeof value !== 'string' || value.trim() === '') {
            errors.push(`${where}: missing`);
        } else if (chars(value) > max) {
            errors.push(`${where}: ${chars(value)} characters, limit ${max}`);
        }
    };
    const count = (where: string, list: unknown, min: number, max: number) => {
        if (!Array.isArray(list) || list.length < min || list.length > max) {
            errors.push(`${where}: needs ${min} to ${max}`);
            return false;
        }
        return true;
    };

    len('title', deck.title, LIMITS.deck.title);
    if (!Array.isArray(deck.slides)) {
        errors.push('slides: missing or not a list');

        return errors;
    }
    const n = deck.slides.length;
    if (n < LIMITS.deck.minSlides || n > LIMITS.deck.maxSlides) {
        errors.push(
            `slides: ${n}, needs ${LIMITS.deck.minSlides} to ${LIMITS.deck.maxSlides}`,
        );
    }
    if (n > 0 && deck.slides[0]?.template !== 'title') {
        errors.push('slides[0]: the first slide must be "title"');
    }
    if (n > 0 && deck.slides[n - 1]?.template === 'section') {
        errors.push(`slides[${n - 1}]: "section" cannot be the last slide`);
    }

    deck.slides.forEach((slide, i) => {
        const at = `slides[${i}]`;
        if (!isObject(slide)) {
            errors.push(`${at}: not an object`);

            return;
        }
        if (!Array.isArray(slide.citations)) {
            errors.push(`${at}.citations: missing`);
        }
        if (!(TEMPLATES as readonly string[]).includes(slide.template)) {
            errors.push(`${at}.template: unknown "${String(slide.template)}"`);

            return;
        }
        if (!isObject(slide.fields)) {
            errors.push(`${at}.fields: missing`);

            return;
        }
        switch (slide.template) {
            case 'title': {
                const f = slide.fields;
                len(`${at}.heading`, f.heading, LIMITS.title.heading);
                len(`${at}.subheading`, f.subheading, LIMITS.title.subheading);
                break;
            }
            case 'section': {
                const f = slide.fields;
                len(`${at}.heading`, f.heading, LIMITS.section.heading);
                len(`${at}.kicker`, f.kicker, LIMITS.section.kicker);
                break;
            }
            case 'bullets': {
                const f = slide.fields;
                const L = LIMITS.bullets;
                len(`${at}.heading`, f.heading, L.heading);
                if (count(`${at}.items`, f.items, L.minItems, L.maxItems)) {
                    f.items.forEach((t, k) =>
                        len(`${at}.items[${k}]`, t, L.item),
                    );
                }
                break;
            }
            case 'two_columns': {
                const f = slide.fields;
                const L = LIMITS.two_columns;
                len(`${at}.heading`, f.heading, L.heading);
                for (const side of ['left', 'right'] as const) {
                    const c = f[side];
                    len(`${at}.${side}.label`, c?.label, L.label);
                    if (
                        count(
                            `${at}.${side}.items`,
                            c?.items,
                            L.minItems,
                            L.maxItems,
                        )
                    ) {
                        c.items.forEach((t, k) =>
                            len(`${at}.${side}.items[${k}]`, t, L.item),
                        );
                    }
                }
                break;
            }
            case 'big_number': {
                const f = slide.fields;
                const L = LIMITS.big_number;
                len(`${at}.heading`, f.heading, L.heading);
                len(`${at}.number`, f.number, L.number);
                len(`${at}.label`, f.label, L.label);
                len(`${at}.context`, f.context, L.context);
                break;
            }
            case 'quote': {
                const f = slide.fields;
                len(`${at}.quote`, f.quote, LIMITS.quote.quote);
                len(
                    `${at}.attribution`,
                    f.attribution,
                    LIMITS.quote.attribution,
                );
                break;
            }
            case 'steps': {
                const f = slide.fields;
                const L = LIMITS.steps;
                len(`${at}.heading`, f.heading, L.heading);
                if (count(`${at}.steps`, f.steps, L.minSteps, L.maxSteps)) {
                    f.steps.forEach((s, k) => {
                        len(`${at}.steps[${k}].label`, s?.label, L.label);
                        len(`${at}.steps[${k}].text`, s?.text, L.text);
                    });
                }
                break;
            }
        }
    });

    return errors;
}

function isObject(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}
