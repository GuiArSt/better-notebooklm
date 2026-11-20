import { describe, expect, it } from 'vitest';
import { readingHint } from '@/lib/source-readiness';
import type { Source } from '@/types/app';

const source = (status: Source['status']) => ({ status }) as Source;

describe('readingHint', () => {
    it('says reading, with the progress, while a source is still being read', () => {
        expect(
            readingHint([
                source('ready'),
                source('pending'),
                source('pending'),
            ]),
        ).toBe('Reading your sources… 1 of 3 ready.');
    });

    it('says nothing when none is being read', () => {
        expect(readingHint([])).toBeNull();
        expect(readingHint([source('ready'), source('failed')])).toBeNull();
    });
});
