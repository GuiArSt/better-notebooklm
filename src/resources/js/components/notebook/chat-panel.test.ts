import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

const source = readFileSync(
    new URL('./chat-panel.tsx', import.meta.url),
    'utf8',
);

describe('the chat keeps no state that lies (HF-10)', () => {
    it('has no thumbs that do nothing', () => {
        expect(source).not.toContain('ThumbsUp');
        expect(source).not.toContain('ThumbsDown');
        expect(source).not.toContain('setRated');
    });

    it('keeps “saved” with the turn, so it survives a reload and clears with the chat', () => {
        expect(source).not.toContain('useState<Set<number>>');
        expect(source).toContain('{ ...t, saved: true }');
    });

    it('shows an error event as the red line under the partial answer, the same as a cut-off stream (Argus gap 3)', () => {
        expect(source).toContain("event.type === 'error'");
        expect(source).toContain("event.message ?? 'Something went wrong.'");
        expect(source).toContain('t.error ?? CUT_OFF');
    });

    it('asks the server to write the overview again from its Try again button (POST, then polls)', () => {
        expect(source).toContain("`/notebooks/${notebookId}/overview`, 'POST'");
        expect(source).toContain("status: 'building'");
        expect(source).toContain('onClick={refreshOverview}');
        expect(source).toContain('3000');
    });
});
