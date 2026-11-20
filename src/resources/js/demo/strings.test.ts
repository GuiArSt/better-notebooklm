import { describe, expect, it } from 'vitest';
import {
    CHECKLIST,
    SILENCE,
    TOGGLE,
    WELCOME,
    WINKS,
    fill,
    money,
} from '@/demo/strings';
import type { Bilingual } from '@/demo/strings';

const all: Bilingual[] = [
    TOGGLE,
    SILENCE,
    WELCOME.badge,
    WELCOME.title,
    ...WELCOME.paragraphs,
    CHECKLIST.title,
    CHECKLIST.start,
    CHECKLIST.hide,
    CHECKLIST.show,
    CHECKLIST.tick,
    CHECKLIST.untick,
    ...CHECKLIST.steps,
    CHECKLIST.doneTitle,
    CHECKLIST.doneBody,
    ...Object.values(WINKS),
];

const holes = (text: string) => (text.match(/\{\w+\}/g) ?? []).sort();

describe('the reviewer strings', () => {
    it('has a German and an English text everywhere', () => {
        for (const b of all) {
            expect(b.de.trim()).not.toBe('');
            expect(b.en.trim()).not.toBe('');
        }
    });

    it('has the same {placeholders} in both languages', () => {
        for (const b of all) {
            expect(holes(b.de)).toEqual(holes(b.en));
        }
    });

    it('fill keeps unknown keys visible', () => {
        expect(fill('Hi {name}, {cap}', { name: 'Ann' })).toBe('Hi Ann, {cap}');
    });

    it('formats money per language', () => {
        // Intl puts a no-break space before the German dollar sign.
        expect(money(25, 'de').replace(/\s/g, ' ')).toBe('25,00 $');
        expect(money(25, 'en')).toBe('$25.00');
    });
});
