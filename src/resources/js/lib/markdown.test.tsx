import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import { Markdown } from '@/lib/markdown';

const known = new Set([260, 261, 7]);
const cite = (id: number, key: string) =>
    known.has(id) ? <b key={key} data-chip={id} /> : null;
const html = (text: string) =>
    renderToStaticMarkup(<Markdown text={text} cite={cite} />);

describe('the Markdown reader', () => {
    it('draws one chip per id of a grouped citation and drops ids the server did not validate', () => {
        const out = html('Claim [P260, P261, P999]. Another [P7].');

        expect(out).toContain('data-chip="260"');
        expect(out).toContain('data-chip="261"');
        expect(out).toContain('data-chip="7"');
        expect(out).not.toContain('999');
        expect(out).not.toContain('[P');
    });

    it('draws chips for [P260; P261] and [ P7 ] and still drops unvalidated ids', () => {
        const out = html('A [P260; P261; P5] and B [ P7 ].');

        expect(out).toContain('data-chip="260"');
        expect(out).toContain('data-chip="261"');
        expect(out).toContain('data-chip="7"');
        expect(out).not.toMatch(/\[|P5|P7\b/);
    });

    it('never leaves a raw marker, even when nothing is validated', () => {
        expect(html('Only unknown [P1, P2].')).not.toMatch(/\[P|P1|P2/);
    });

    it('turns --- into a thin divider and injects no HTML', () => {
        const out = html('Above\n\n---\n\nBelow <script>alert(1)</script>');

        expect(out).toContain('<hr');
        expect(out).not.toContain('<script');
        expect(out).toContain('&lt;script&gt;');
    });
});
