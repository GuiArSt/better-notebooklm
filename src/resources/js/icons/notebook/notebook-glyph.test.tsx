import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import {
    DEFAULT_EMOJI,
    NOTEBOOK_GLYPHS,
    NotebookGlyph,
    notebookGlyph,
} from '@/icons/notebook/notebook-glyph';

/** The emojis the picker offers, read from its source, so a new emoji there without a drawing fails here. */
const pickerEmojis = (): string[] => {
    const source = readFileSync(
        resolve(__dirname, '../../components/emoji-picker.tsx'),
        'utf8',
    );
    const list = source.slice(source.indexOf('['), source.indexOf('];'));

    return [...list.matchAll(/'([^']+)'/g)].map((m) => m[1]);
};

describe('the notebook glyphs', () => {
    it('draws every emoji the picker offers', () => {
        const offered = pickerEmojis();

        expect(offered.length).toBeGreaterThanOrEqual(16);
        for (const emoji of offered) {
            expect(
                NOTEBOOK_GLYPHS[emoji],
                `no drawing for ${emoji}`,
            ).toBeDefined();
        }
    });

    it('draws the defaults the server stores: the notebook and the featured notebook', () => {
        expect(NOTEBOOK_GLYPHS[DEFAULT_EMOJI].label).toBe('Notebook');
        expect(NOTEBOOK_GLYPHS['🧪'].label).toBe('Lab');
    });

    it('gives each topic its own name and its own drawing', () => {
        const all = Object.values(NOTEBOOK_GLYPHS);
        const drawings = Object.keys(NOTEBOOK_GLYPHS).map((e) =>
            renderToStaticMarkup(<NotebookGlyph emoji={e} />).replace(
                /data-glyph="[^"]*"/,
                '',
            ),
        );

        expect(new Set(all.map((g) => g.label)).size).toBe(all.length);
        expect(new Set(drawings).size).toBe(drawings.length);
    });

    it('falls back to the notebook for an emoji it has no drawing for, or none', () => {
        expect(notebookGlyph('🦄').label).toBe('Notebook');
        expect(notebookGlyph(null).label).toBe('Notebook');
        expect(notebookGlyph(undefined).label).toBe('Notebook');
    });

    it('is decorative by default, and named when asked (a picker option)', () => {
        const plain = renderToStaticMarkup(<NotebookGlyph emoji="🎨" />);
        const named = renderToStaticMarkup(<NotebookGlyph emoji="🎨" label />);

        expect(plain).toContain('aria-hidden="true"');
        expect(plain).not.toContain('role="img"');
        expect(named).toContain('role="img"');
        expect(named).toContain('aria-label="Art"');
    });

    it('never puts the emoji itself in the markup', () => {
        for (const emoji of Object.keys(NOTEBOOK_GLYPHS)) {
            expect(
                renderToStaticMarkup(<NotebookGlyph emoji={emoji} label />),
            ).not.toContain(emoji);
        }
    });

    it('passes a class through, for the size', () => {
        expect(
            renderToStaticMarkup(
                <NotebookGlyph emoji="📓" className="size-6" />,
            ),
        ).toContain('size-6');
    });
});
