import { describe, expect, it } from 'vitest';
import { CITATION_GROUP, citationIds, stripCitations } from '@/lib/citations';

describe('citation markers', () => {
    it('accepts semicolons and spaces inside the brackets, as the server does', () => {
        const only = new RegExp(`^${CITATION_GROUP.source}$`);

        for (const marker of [
            '[P12; P13]',
            '[ P12 ]',
            '[ P12 ; P13 ]',
            '[P12;13]',
        ]) {
            expect(only.test(marker), marker).toBe(true);
        }

        expect(citationIds('[ P12 ; P13 ]')).toEqual([12, 13]);
        expect(stripCitations('A [ P1 ] B [P2; P3] C')).toBe('A B C');
    });

    it('reads one id and a comma-separated group inside one bracket', () => {
        expect(citationIds('[P12]')).toEqual([12]);
        expect(citationIds('[P260, P261]')).toEqual([260, 261]);
        expect(citationIds('[P253, 273]')).toEqual([253, 273]);
        expect(citationIds('[P5,P5,P6]')).toEqual([5, 6]);
    });

    it('matches only well-formed markers', () => {
        const only = new RegExp(`^${CITATION_GROUP.source}$`);

        expect(only.test('[P264, P265]')).toBe(true);
        expect(only.test('[P12]')).toBe(true);
        expect(only.test('[P12')).toBe(false);
        expect(only.test('[Q12]')).toBe(false);
        expect(only.test('[P12 / P13]')).toBe(false);
    });

    it('strips every marker, single or grouped, for copying', () => {
        expect(stripCitations('One [P1]. Two [P260, P261]. Three.')).toBe(
            'One. Two. Three.',
        );
    });
});
