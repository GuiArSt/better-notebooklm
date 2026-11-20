import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import {
    SOURCE_KINDS,
    SourceIcon,
    sourceKind,
} from '@/icons/sources/source-icon';
import type { SourceKind } from '@/icons/sources/source-icon';

const KINDS = Object.keys(SOURCE_KINDS) as SourceKind[];

describe('the source icons', () => {
    it.each(KINDS)(
        'draws %s with its own label and tint, for screen readers and the eye',
        (kind) => {
            const out = renderToStaticMarkup(<SourceIcon kind={kind} />);

            expect(out).toContain('role="img"');
            expect(out).toContain(`aria-label="${SOURCE_KINDS[kind].label}"`);
            expect(out).toContain(SOURCE_KINDS[kind].tint.split(' ')[0]);
            expect(out).toContain(`data-kind="${kind}"`);
        },
    );

    it('gives every kind a different colour and a different label', () => {
        const tints = KINDS.map((k) => SOURCE_KINDS[k].tint);
        const labels = KINDS.map((k) => SOURCE_KINDS[k].label);

        expect(new Set(tints).size).toBe(KINDS.length);
        expect(new Set(labels).size).toBe(KINDS.length);
    });

    it('draws an unknown kind as text, labelled plainly, rather than nothing', () => {
        const out = renderToStaticMarkup(<SourceIcon kind="spreadsheet" />);

        expect(sourceKind('spreadsheet')).toBe('text');
        expect(out).toContain('aria-label="Source"');
        expect(out).toContain('data-kind="unknown"');
    });

    it('keeps a size class the row passes in', () => {
        expect(
            renderToStaticMarkup(<SourceIcon kind="pdf" className="size-5" />),
        ).toContain('size-5');
    });
});
