import { describe, expect, it } from 'vitest';
import { STYLE_SWATCHES, swatchFor } from '@/lib/style-swatches';

describe('style swatches', () => {
    it('has one for each of the server’s infographic styles and a neutral for an unknown one', () => {
        for (const style of [
            'auto',
            'editorial',
            'sketch',
            'clay',
            'icons',
            'blueprint',
            'paper',
        ]) {
            expect(STYLE_SWATCHES[style]).toBeTruthy();
        }

        expect(swatchFor('nope')).toContain('gradient');
    });
});
