import { readFileSync } from 'node:fs';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import { NotebookIcon } from '@/components/notebook-icon';

describe('NotebookIcon', () => {
    it('shows the drawn icon until the generated icon is done, in the same box, never the emoji (D-059)', () => {
        const emoji = renderToStaticMarkup(
            <NotebookIcon id={4} emoji="🧪" status="queued" size="md" />,
        );
        const none = renderToStaticMarkup(
            <NotebookIcon id={4} emoji="🧪" status={null} size="md" />,
        );
        const image = renderToStaticMarkup(
            <NotebookIcon id={4} emoji="🧪" status="done" size="md" />,
        );

        expect(emoji).toContain('data-glyph="lab"');
        expect(emoji).not.toContain('🧪');
        expect(emoji).not.toContain('<img');
        expect(none).toContain('data-kind="glyph"');
        expect(image).toContain('src="/notebooks/4/icon"');
        expect(image).toContain('data-kind="image"');
        // one box: the same size classes for both
        const box = (html: string) => html.match(/size-12[^"]*/)?.[0];

        expect(box(image)).toBe(box(emoji));
    });

    it('never shows a failed icon: failed and running fall back to the drawn icon', () => {
        for (const status of ['failed', 'running', 'queued']) {
            expect(
                renderToStaticMarkup(
                    <NotebookIcon id={1} emoji="📚" status={status} />,
                ),
            ).not.toContain('<img');
        }
    });

    it('falls back to the drawn icon through state, not by changing the DOM from outside React (HF-23)', () => {
        const source = readFileSync(
            new URL('./notebook-icon.tsx', import.meta.url),
            'utf8',
        );

        expect(source).not.toContain('insertAdjacentText');
        expect(source).not.toContain('style.display');
        expect(source).toContain('onError={() => setBrokenId(id)}');
    });
});
