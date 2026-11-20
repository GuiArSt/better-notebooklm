import { describe, expect, it } from 'vitest';
import { isDemoGuest } from '@/demo/flag';

function memory(): Storage {
    const data = new Map<string, string>();

    return {
        getItem: (k: string) => data.get(k) ?? null,
        setItem: (k: string, v: string) => void data.set(k, v),
        removeItem: (k: string) => void data.delete(k),
    } as unknown as Storage;
}

describe('isDemoGuest', () => {
    it('is true for a reviewer, false for a member', () => {
        expect(isDemoGuest({ role: 'reviewer' }, '', memory())).toBe(true);
        expect(
            isDemoGuest({ role: 'member' }, '?demo=reviewer', memory()),
        ).toBe(false);
        expect(isDemoGuest(null, '', memory())).toBe(false);
    });

    it('is true for the owner only with ?demo=reviewer, and remembers it', () => {
        const store = memory();

        expect(isDemoGuest({ role: 'owner' }, '', store)).toBe(false);
        expect(isDemoGuest({ role: 'owner' }, '?demo=reviewer', store)).toBe(
            true,
        );
        expect(isDemoGuest({ role: 'owner' }, '', store)).toBe(true);
    });

    it('clears the preview with ?demo=off', () => {
        const store = memory();

        isDemoGuest({ role: 'owner' }, '?demo=reviewer', store);

        expect(isDemoGuest({ role: 'owner' }, '?demo=off', store)).toBe(false);
        expect(isDemoGuest({ role: 'owner' }, '', store)).toBe(false);
    });

    it('still answers when the storage throws', () => {
        const broken = {
            getItem: () => {
                throw new Error('blocked');
            },
            setItem: () => {
                throw new Error('blocked');
            },
            removeItem: () => {
                throw new Error('blocked');
            },
        } as unknown as Storage;

        expect(isDemoGuest({ role: 'owner' }, '?demo=reviewer', broken)).toBe(
            true,
        );
        expect(isDemoGuest({ role: 'owner' }, '', broken)).toBe(false);
    });
});
