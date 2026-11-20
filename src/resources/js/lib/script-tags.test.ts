import { describe, expect, it } from 'vitest';
import { scriptParts } from '@/lib/script-tags';

describe('scriptParts', () => {
    it('separates the audio tags from the words', () => {
        expect(
            scriptParts('[whispers] Nobody knew. [pause] Then it flew.'),
        ).toEqual([
            { text: 'whispers', tag: true },
            { text: 'Nobody knew.', tag: false },
            { text: 'pause', tag: true },
            { text: 'Then it flew.', tag: false },
        ]);
    });

    it('leaves a line without tags whole', () => {
        expect(scriptParts('Just words.')).toEqual([
            { text: 'Just words.', tag: false },
        ]);
    });
});
