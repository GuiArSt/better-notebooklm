import { describe, expect, it } from 'vitest';
import { retryUrl } from '@/lib/retry-source';

describe('retryUrl', () => {
    it('names the source’s retry route inside its notebook', () => {
        expect(retryUrl(7, 42)).toBe('/notebooks/7/sources/42/retry');
    });
});
