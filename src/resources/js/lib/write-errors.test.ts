import { describe, expect, it } from 'vitest';
import { writeErrorMessage } from '@/lib/write-errors';

const at = (path: string) => new URL(path, 'https://example.test');

describe('writeErrorMessage', () => {
    it('gives the first message of a refused notebook write', () => {
        expect(
            writeErrorMessage(at('/notebooks/3/notes'), {
                content: 'The content is too long.',
                citations: 'Too many.',
            }),
        ).toBe('The content is too long.');
    });

    it('leaves the forms outside notebooks to show their own errors', () => {
        expect(writeErrorMessage(at('/login'), { email: 'Wrong.' })).toBeNull();
    });

    it('says nothing when there are no errors', () => {
        expect(writeErrorMessage(at('/notebooks/3/notes'), {})).toBeNull();
    });
});
