import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

const read = (file: string) =>
    readFileSync(new URL(file, import.meta.url), 'utf8');

describe('a failed source can be read again (Argus gap 5)', () => {
    it('has a Retry beside the red triangle in the list', () => {
        const panel = read('./sources-panel.tsx');

        expect(panel).toContain('data-testid="source-failed"');
        expect(panel).toContain('data-testid="source-retry"');
        expect(panel).toContain('retrySource(');
    });

    it('has a Retry in the viewer, and looks again while the source is being read', () => {
        const viewer = read('./source-viewer.tsx');

        expect(viewer).toContain('data-testid="source-viewer-retry"');
        expect(viewer).toContain("status: 'pending'");
        expect(viewer).toContain('setInterval');
    });
});
