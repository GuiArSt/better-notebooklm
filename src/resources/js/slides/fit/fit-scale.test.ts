import { describe, expect, it } from 'vitest';
import { fitScale } from '@/slides/fit/fit-scale';

describe('fitScale', () => {
    it('returns max when nothing overflows', () => {
        expect(fitScale(() => false)).toBe(1);
        expect(fitScale(() => false, { max: 0.9 })).toBe(0.9);
    });

    it('steps down to the first scale that fits', () => {
        expect(fitScale((s) => s > 0.8)).toBe(0.8);
        expect(fitScale((s) => s > 0.7, { step: 0.1 })).toBe(0.7);
    });

    it('returns min when nothing fits', () => {
        expect(fitScale(() => true)).toBe(0.6);
        expect(fitScale(() => true, { min: 0.45 })).toBe(0.45);
    });

    it('never leaves the range, and only asks about scales inside it', () => {
        const asked: number[] = [];
        const result = fitScale(
            (s) => {
                asked.push(s);

                return true;
            },
            { min: 0.5, max: 0.9, step: 0.07 },
        );
        expect(result).toBe(0.5);
        expect(Math.max(...asked)).toBeLessThanOrEqual(0.9);
        expect(Math.min(...asked)).toBeGreaterThanOrEqual(0.5);
    });
});
