import { describe, expect, it } from 'vitest';
import { money, usd } from '@/lib/money';
import { clock } from '@/lib/studio-kinds';

describe('money', () => {
    it('shows three decimals below a dollar and two above', () => {
        expect(usd(0.0123)).toBe('$0.012');
        expect(usd(5)).toBe('$5.00');
        expect(money(1.5)).toBe('$1.50');
    });
});

describe('clock', () => {
    it('is m:ss and never rounds up to 1:60', () => {
        expect(clock(185)).toBe('3:05');
        expect(clock(119.6)).toBe('1:59');
        expect(clock(NaN)).toBe('0:00');
        expect(clock(-4)).toBe('0:00');
    });
});
