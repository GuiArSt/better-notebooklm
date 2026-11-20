import { describe, expect, it } from 'vitest';
import { largeNotice } from '@/lib/large-source';

const large = {
    large: true,
    status: 'pending' as const,
    page_count: 158,
    estimated_cost_micro_usd: 24_000,
};

describe('largeNotice', () => {
    it('says the pages and the cost in the server’s numbers while a large source is read', () => {
        expect(largeNotice(large)).toBe(
            'Large source: about 158 pages. Reading it takes a few minutes and uses about $0.024 of your budget.',
        );
    });

    it('says nothing for a small source, a finished one or one without numbers', () => {
        expect(largeNotice({ ...large, large: false })).toBeNull();
        expect(largeNotice({ ...large, status: 'ready' })).toBeNull();
        expect(largeNotice({ ...large, page_count: null })).toBeNull();
    });

    it('leaves the cost out when the server sent none', () => {
        expect(largeNotice({ ...large, estimated_cost_micro_usd: null })).toBe(
            'Large source: about 158 pages. Reading it takes a few minutes.',
        );
    });
});
