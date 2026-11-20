import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

const source = readFileSync(
    new URL('./studio-panel.tsx', import.meta.url),
    'utf8',
);

describe('the Studio panel does not hide failures (HF-3, HF-4)', () => {
    it('has no empty catch and no swallowed promise', () => {
        expect(source).not.toMatch(
            /catch\s*(\([^)]*\))?\s*\{\s*(\/\/[^\n]*\s*)?\}/,
        );
        expect(source).not.toContain('.catch(() => undefined)');
    });

    it('takes the server’s own words, not a matched status string', () => {
        expect(source).not.toContain("includes('429')");
        expect(source).not.toContain('Two items are already being made');
        expect(source).toContain('e.messages()');
    });
});
