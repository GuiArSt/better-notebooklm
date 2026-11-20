import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

const source = readFileSync(
    new URL('./customize-dialog.tsx', import.meta.url),
    'utf8',
);

describe('the Customize dialog on a small screen (visual pass 7)', () => {
    it('keeps Estimate, Cancel and Generate in a sticky footer', () => {
        expect(source).toMatch(/<DialogFooter className="sticky -bottom-6/);
        expect(source).toContain('data-testid="customize-generate"');
    });

    it('lays the option pills out in two columns on a phone instead of wrapping inside a stretched pill', () => {
        expect(source).toContain('grid grid-cols-2 gap-1');
        expect(source).toContain('sm:flex sm:w-fit');
    });

    it('lays the infographic’s seven styles out as a grid of equal tiles with a swatch each, not wrapping pills', () => {
        expect(source).toContain('grid grid-cols-2 gap-2 sm:grid-cols-4');
        expect(source).toContain('customize-tile-');
        expect(source).toContain('swatchFor(o.value)');
    });
});
