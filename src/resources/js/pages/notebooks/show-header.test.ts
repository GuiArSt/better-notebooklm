import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

const source = readFileSync(new URL('./show.tsx', import.meta.url), 'utf8');

describe('the phone header keeps the notebook’s title (visual pass 8)', () => {
    it('wraps the title to two lines and moves the budget to its own row', () => {
        expect(source).toContain('line-clamp-2 min-w-0 flex-1');
        expect(source).toContain('sm:truncate');
        expect(source).toContain('order-last w-full sm:hidden');
        expect(source).toContain('flex flex-wrap items-center');
    });
});
