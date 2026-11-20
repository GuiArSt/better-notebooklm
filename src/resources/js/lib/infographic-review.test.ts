import { describe, expect, it } from 'vitest';
import { reviewNote } from '@/lib/infographic-review';
import type { StudioItem } from '@/types/studio';

const item = (review: unknown, kind = 'infographic') =>
    ({ kind, settings: { review } }) as unknown as StudioItem;

describe('reviewNote', () => {
    it('lists the problems the check found', () => {
        const note = reviewNote(
            item({
                verdict: 'bad',
                attempts: 2,
                problems: ['A number is wrong.', 'A word is cut off.'],
            }),
        );

        expect(note).toMatchObject({
            verdict: 'bad',
            heading: 'The check found problems in this picture:',
        });
        expect(note?.verdict === 'bad' && note.problems).toEqual([
            'A number is wrong.',
            'A word is cut off.',
        ]);
    });

    it('says plainly when the review did not answer', () => {
        expect(
            reviewNote(item({ verdict: 'unavailable', attempts: 1 })),
        ).toMatchObject({
            text: 'Not checked: the review didn’t answer',
        });
    });

    it('says nothing for a good check, no record or another kind', () => {
        expect(reviewNote(item({ verdict: 'good', attempts: 1 }))).toBeNull();
        expect(
            reviewNote({ kind: 'infographic', settings: null } as StudioItem),
        ).toBeNull();
        expect(
            reviewNote(
                item({ verdict: 'bad', attempts: 1, problems: ['x'] }, 'quiz'),
            ),
        ).toBeNull();
    });
});
