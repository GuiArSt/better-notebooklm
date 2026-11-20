import { describe, expect, it } from 'vitest';
import { isNotebookVisit } from '@/lib/notebook-visit';

describe('isNotebookVisit', () => {
    it('is true for a notebook’s own page only', () => {
        expect(isNotebookVisit('/notebooks/12')).toBe(true);
        expect(isNotebookVisit('/notebooks/12/')).toBe(true);
        expect(isNotebookVisit('/notebooks')).toBe(false);
        expect(isNotebookVisit('/notebooks/12/studio')).toBe(false);
        expect(isNotebookVisit('/pricing')).toBe(false);
    });
});
