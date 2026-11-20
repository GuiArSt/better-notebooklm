import { describe, expect, it } from 'vitest';
import { dayHeader, headerBefore } from '@/lib/chat-days';

const now = new Date(2026, 10, 19, 12, 0);
const at = (day: number, hour = 9) =>
    new Date(2026, 10, day, hour).toISOString();

describe('the chat’s day headers', () => {
    it('name each message’s own day', () => {
        expect(dayHeader(at(19), now)).toBe('Today');
        expect(dayHeader(at(18), now)).toBe('Yesterday');
        expect(dayHeader(at(12), now)).toBe('Thursday, November 12');
    });

    it('show a header only where the day changes', () => {
        const turns = [
            { asked_at: at(18, 9) },
            { asked_at: at(18, 15) },
            { asked_at: at(19, 8) },
        ];

        expect(turns.map((_, i) => headerBefore(turns, i, now))).toEqual([
            'Yesterday',
            null,
            'Today',
        ]);
    });

    it('shows none for a turn saved before times were recorded', () => {
        expect(headerBefore([{}], 0, now)).toBeNull();
    });
});
