import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

const source = readFileSync(
    new URL('./frame-layout.tsx', import.meta.url),
    'utf8',
);

describe('the frame keeps a long page whole (visual pass 4)', () => {
    it('lets the footer scroll with a long page and pins it only for the notebook', () => {
        expect(source).toContain("usePage().component === 'notebooks/show'");
        expect(source).toContain('min-h-full flex-col');
        expect(source.match(/<SiteFooter/g)).toHaveLength(2);
    });

    it('shows the notebook skeleton the moment a notebook visit starts (visual pass 9)', () => {
        expect(source).toContain("router.on('start'");
        expect(source).toContain(
            'isNotebookVisit(e.detail.visit.url.pathname)',
        );
        expect(source).toContain('<NotebookSkeleton />');
    });
});
