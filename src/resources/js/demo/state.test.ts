import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    DEFAULT_STATE,
    canWink,
    claimWink,
    getState,
    parseState,
    resetMemory,
    setLang,
    setState,
    silence,
    tick,
    untick,
    withWink,
} from '@/demo/state';

afterEach(() => {
    vi.unstubAllGlobals();
    resetMemory();
});

function stubStorage(overrides: Partial<Storage>) {
    const data = new Map<string, string>();

    vi.stubGlobal('window', {
        localStorage: {
            getItem: (k: string) => data.get(k) ?? null,
            setItem: (k: string, v: string) => void data.set(k, v),
            ...overrides,
        },
    });

    return data;
}

describe('the wink rules', () => {
    it('lets a wink fire once only', () => {
        const after = withWink(DEFAULT_STATE, 'quiz', 100_000);

        expect(canWink(DEFAULT_STATE, 'quiz', 100_000)).toBe(true);
        expect(canWink(after, 'quiz', 999_999_999)).toBe(false);
    });

    it('allows one a minute', () => {
        const after = withWink(DEFAULT_STATE, 'quiz', 100_000);

        expect(canWink(after, 'meter', 159_999)).toBe(false);
        expect(canWink(after, 'meter', 160_000)).toBe(true);
    });

    it('is blocked once silenced', () => {
        expect(canWink({ ...DEFAULT_STATE, silenced: true }, 'quiz', 1e9)).toBe(
            false,
        );
    });
});

describe('the stored state', () => {
    it('defaults to German and falls back on bad text', () => {
        expect(parseState(null).lang).toBe('de');
        expect(parseState('{not json')).toEqual(DEFAULT_STATE);
        expect(parseState('{"lang":"en","ticked":["ask"]}')).toMatchObject({
            lang: 'en',
            ticked: ['ask'],
        });
    });

    it('is written to storage under demo.v1', () => {
        const data = stubStorage({});

        setLang('en');

        expect(JSON.parse(data.get('demo.v1') ?? '{}').lang).toBe('en');
    });

    it('works from memory when storage throws', () => {
        const boom = () => {
            throw new Error('blocked');
        };
        stubStorage({ getItem: boom, setItem: boom });

        tick('ask');
        tick('ask');
        tick('cite');
        untick('cite');
        setLang('en');

        expect(getState()).toMatchObject({ lang: 'en', ticked: ['ask'] });
        expect(claimWink('quiz', 100_000)).toBe(true);
        expect(claimWink('quiz', 900_000)).toBe(false);
    });

    it('drops a wink that cannot fire, without queueing it', () => {
        stubStorage({});
        setState(DEFAULT_STATE);

        expect(claimWink('quiz', 100_000)).toBe(true);
        expect(claimWink('meter', 110_000)).toBe(false);
        expect(getState().winked).toEqual(['quiz']);
        silence();
        expect(claimWink('meter', 900_000)).toBe(false);
    });
});
