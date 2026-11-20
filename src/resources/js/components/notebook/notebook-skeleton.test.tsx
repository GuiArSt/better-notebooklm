import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import { NotebookSkeleton } from '@/components/notebook/notebook-skeleton';

describe('NotebookSkeleton', () => {
    it('draws three panels and says it is opening the notebook', () => {
        const html = renderToStaticMarkup(<NotebookSkeleton />);

        expect(html).toContain('data-testid="notebook-skeleton"');
        expect(html).toContain('aria-label="Opening the notebook"');
        expect(html.match(/data-slot="skeleton"/g)!.length).toBeGreaterThan(9);
    });
});
