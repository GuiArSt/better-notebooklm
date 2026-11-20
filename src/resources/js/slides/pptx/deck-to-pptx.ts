import type PptxGenJS from 'pptxgenjs';
import { chars } from '@/slides/types';
import type { Deck, Slide, SlideOf } from '@/slides/types';
import type { Citation } from '@/types/app';

/** The class pptxgenjs exports; the caller loads it (a dynamic import keeps it off the main bundle). */
export type PptxClass = typeof PptxGenJS;

export type PptxOptions = {
    /** Resolves a passage id ("P12") to its citation; ids it cannot resolve are dropped from the footer. */
    cite?: (id: string) => Citation | undefined;
};

/** The brand, as the slides draw it (hex without the #, as pptxgenjs wants). */
const INK = '171717';
const PAPER = 'ffffff';
const MUTED = '636363';
const SIGNAL = '0ea5e9';
const CARD = 'f3f1f1';
const LINE = 'e5e7eb';
const INVERT_MUTED = 'a3a3a3';
const FONT = 'Figtree';

/** 13.333 x 7.5 in (LAYOUT_WIDE). */
const W = 13.333;
const X = 0.8;
const CONTENT_W = W - 2 * X;
const FOOTER_Y = 6.85;

type Pres = PptxGenJS;
type Sl = ReturnType<PptxGenJS['addSlide']>;
type Ctx = { pres: Pres; slide: Sl };

/** The footer's source line: "Sources: A, B" from the resolved citations (each title once), or '' when none resolve. */
export function sourcesLine(
    citations: string[],
    cite?: (id: string) => Citation | undefined,
): string {
    const titles = new Set<string>();
    for (const id of citations) {
        const title = cite?.(id)?.source_title;
        if (title) {
            titles.add(title);
        }
    }

    return titles.size > 0 ? `Sources: ${[...titles].join(', ')}` : '';
}

/**
 * The largest font size, in points, at which the text fits its box once wrapped. Only PowerPoint honours its own
 * shrink-on-overflow, and only after the text is edited (Keynote, Quick Look and Google Slides ignore it), so the size
 * is chosen here. The estimate: an average glyph 0.55 em wide (0.6 bold), lines 1.2 em, a paragraph gap per list item.
 */
export function fitFontSize(
    paragraphs: string[],
    w: number,
    h: number,
    max: number,
    {
        bold = false,
        paraGapPt = 0,
        min = Math.max(8, Math.round(max * 0.4)),
    } = {},
): number {
    for (let size = max; size > min; size--) {
        const perLine = Math.max(
            1,
            Math.floor((w * 72) / (size * (bold ? 0.6 : 0.55))),
        );
        const lines = paragraphs.reduce(
            (n, p) => n + Math.max(1, Math.ceil(chars(p) / perLine)),
            0,
        );
        const height =
            (lines * size * 1.2 + paragraphs.length * paraGapPt) / 72;
        if (height <= h) {
            return size;
        }
    }

    return min;
}

const text = (
    { slide }: Ctx,
    value: string | PptxGenJS.TextProps[],
    x: number,
    y: number,
    w: number,
    h: number,
    options: PptxGenJS.TextPropsOptions = {},
) => {
    const list = Array.isArray(value);
    const fontSize =
        options.fontSize && options.fit !== 'none'
            ? fitFontSize(
                  list ? value.map((v) => v.text ?? '') : [value],
                  list ? w - 0.3 : w, // a bullet's indent
                  h,
                  options.fontSize,
                  { bold: options.bold === true, paraGapPt: list ? 8 : 0 },
              )
            : options.fontSize;

    return slide.addText(value, {
        x,
        y,
        w,
        h,
        fontFace: FONT,
        color: INK,
        margin: 0,
        valign: 'top',
        fit: 'shrink',
        ...options,
        fontSize,
    });
};

/** The mark's agent: a small rounded square. */
const agent = (
    { pres, slide }: Ctx,
    x: number,
    y: number,
    size: number,
    color: string,
) =>
    slide.addShape(pres.ShapeType.roundRect, {
        x,
        y,
        w: size,
        h: size,
        rectRadius: size * 0.2,
        fill: { color },
        line: { color, width: 0 },
    });

const heading = (c: Ctx, value: string) =>
    text(c, value, X, 0.7, CONTENT_W * 0.88, 1.3, {
        fontSize: 30,
        bold: true,
        valign: 'top',
    });

function title(c: Ctx, f: SlideOf<'title'>['fields']) {
    agent(c, X, 1.62, 0.16, SIGNAL);
    text(c, 'SLIDE DECK', X + 0.3, 1.52, 4, 0.36, {
        fontSize: 12,
        bold: true,
        color: MUTED,
        charSpacing: 2,
    });
    text(c, f.heading, X, 2.1, 9.6, 2.3, {
        fontSize: 44,
        bold: true,
        valign: 'bottom',
    });
    text(c, f.subheading, X, 4.6, 8.4, 1.5, { fontSize: 20, color: MUTED });
}

function section(c: Ctx, f: SlideOf<'section'>['fields']) {
    agent(c, X, 2.4, 0.16, SIGNAL);
    text(c, f.kicker.toUpperCase(), X + 0.3, 2.3, 8, 0.36, {
        fontSize: 14,
        bold: true,
        color: SIGNAL,
        charSpacing: 3,
    });
    text(c, f.heading, X, 2.9, 9, 2.4, {
        fontSize: 40,
        bold: true,
        color: 'fafafa',
    });
}

function bullets(c: Ctx, f: SlideOf<'bullets'>['fields']) {
    heading(c, f.heading);
    const top = 2.1;
    const slot = 4.5 / f.items.length;
    f.items.forEach((item, i) => {
        const y = top + i * slot;
        agent(c, X, y + 0.1, 0.16, i === 0 ? SIGNAL : MUTED);
        text(c, item, X + 0.4, y, CONTENT_W * 0.86, slot - 0.1, {
            fontSize: 20,
        });
    });
}

function twoColumns(c: Ctx, f: SlideOf<'two_columns'>['fields']) {
    heading(c, f.heading);
    const gap = 0.3;
    const w = (CONTENT_W - gap) / 2;
    ([f.left, f.right] as const).forEach((col, side) => {
        const x = X + side * (w + gap);
        c.slide.addShape(c.pres.ShapeType.roundRect, {
            x,
            y: 2.1,
            w,
            h: 4.5,
            rectRadius: 0.2,
            fill: { color: CARD },
            line: { color: CARD, width: 0 },
        });
        agent(c, x + 0.3, 2.5, 0.14, side === 1 ? SIGNAL : MUTED);
        text(c, col.label.toUpperCase(), x + 0.55, 2.4, w - 0.85, 0.36, {
            fontSize: 12,
            bold: true,
            color: MUTED,
            charSpacing: 2,
        });
        text(
            c,
            col.items.map((item) => ({
                text: item,
                options: {
                    bullet: { indent: 16 },
                    breakLine: true,
                    paraSpaceAfter: 8,
                },
            })),
            x + 0.3,
            2.95,
            w - 0.6,
            3.45,
            { fontSize: 16 },
        );
    });
}

function bigNumber(c: Ctx, f: SlideOf<'big_number'>['fields']) {
    heading(c, f.heading);
    text(c, f.number, X, 2.5, 6.2, 1.7, {
        fontSize: 72,
        bold: true,
        valign: 'middle',
    });
    agent(c, X, 4.5, 0.16, SIGNAL);
    text(c, f.label, X + 0.3, 4.4, 5.9, 1.2, { fontSize: 18, bold: true });
    c.slide.addShape(c.pres.ShapeType.line, {
        x: 7.4,
        y: 2.6,
        w: 0,
        h: 3.2,
        line: { color: LINE, width: 1 },
    });
    text(c, f.context, 7.7, 2.6, W - X - 7.7, 3.2, {
        fontSize: 18,
        color: MUTED,
    });
}

function quote(c: Ctx, f: SlideOf<'quote'>['fields']) {
    text(c, '“', 1.2, 0.9, 1.5, 1.5, {
        fontSize: 96,
        bold: true,
        color: SIGNAL,
    });
    text(c, f.quote, 1.2, 2.1, W - 2.4, 3.3, {
        fontSize: 28,
        valign: 'middle',
    });
    agent(c, 1.2, 5.78, 0.16, SIGNAL);
    text(c, f.attribution, 1.5, 5.68, W - 2.7, 0.8, {
        fontSize: 16,
        color: MUTED,
    });
}

function steps(c: Ctx, f: SlideOf<'steps'>['fields']) {
    heading(c, f.heading);
    const gap = 0.3;
    const w = (CONTENT_W - gap * (f.steps.length - 1)) / f.steps.length;
    c.slide.addShape(c.pres.ShapeType.line, {
        x: X,
        y: 2.4,
        w: CONTENT_W,
        h: 0,
        line: { color: LINE, width: 1 },
    });
    f.steps.forEach((step, i) => {
        const x = X + i * (w + gap);
        const last = i === f.steps.length - 1;
        c.slide.addShape(c.pres.ShapeType.roundRect, {
            x,
            y: 2.1,
            w: 0.6,
            h: 0.6,
            rectRadius: 0.12,
            fill: { color: last ? SIGNAL : INK },
            line: { color: last ? SIGNAL : INK, width: 0 },
        });
        text(c, String(i + 1), x, 2.1, 0.6, 0.6, {
            fontSize: 16,
            bold: true,
            color: PAPER,
            align: 'center',
            valign: 'middle',
            fit: 'none',
        });
        text(c, step.label, x, 3.0, w, 0.9, { fontSize: 18, bold: true });
        text(c, step.text, x, 3.95, w, 2.6, { fontSize: 15, color: MUTED });
    });
}

function footer(
    c: Ctx,
    slide: Slide,
    index: number,
    deck: Deck,
    cite: PptxOptions['cite'],
) {
    const inverted = slide.template === 'section';
    const muted = inverted ? INVERT_MUTED : MUTED;
    c.slide.addShape(c.pres.ShapeType.line, {
        x: X,
        y: FOOTER_Y,
        w: CONTENT_W,
        h: 0,
        line: { color: inverted ? '404040' : LINE, width: 1 },
    });
    const small = { fontSize: 10, color: muted, valign: 'middle' } as const;
    if (index > 0) {
        text(c, deck.title, X, FOOTER_Y + 0.08, 4, 0.35, small);
    }
    text(
        c,
        sourcesLine(slide.citations, cite),
        4.9,
        FOOTER_Y + 0.08,
        6.2,
        0.35,
        {
            ...small,
            align: 'right',
        },
    );
    text(
        c,
        `${index + 1} / ${deck.slides.length}`,
        W - X - 1,
        FOOTER_Y + 0.08,
        1,
        0.35,
        {
            ...small,
            align: 'right',
        },
    );
}

/** One presentation, 16:9, one slide per deck slide, in the app's brand; long text gets a smaller font so it fits its box (fitFontSize). */
export function buildPptx(
    deck: Deck,
    Pptx: PptxClass,
    opts: PptxOptions = {},
): Pres {
    const pres = new Pptx();
    pres.layout = 'LAYOUT_WIDE';
    pres.title = deck.title;

    deck.slides.forEach((s, index) => {
        const slide = pres.addSlide();
        slide.background = { color: s.template === 'section' ? INK : PAPER };
        const c: Ctx = { pres, slide };
        switch (s.template) {
            case 'title':
                title(c, s.fields);
                break;
            case 'section':
                section(c, s.fields);
                break;
            case 'bullets':
                bullets(c, s.fields);
                break;
            case 'two_columns':
                twoColumns(c, s.fields);
                break;
            case 'big_number':
                bigNumber(c, s.fields);
                break;
            case 'quote':
                quote(c, s.fields);
                break;
            case 'steps':
                steps(c, s.fields);
                break;
        }
        footer(c, s, index, deck, opts.cite);
    });

    return pres;
}

/** The file name for a deck: its title, lower-case and hyphenated, with the .pptx extension. */
export function pptxFileName(deck: Deck): string {
    const slug = deck.title
        .toLowerCase()
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 80)
        .replace(/-+$/g, '');

    return `${slug || 'slides'}.pptx`;
}
