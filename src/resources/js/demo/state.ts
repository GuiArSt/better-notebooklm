import { useSyncExternalStore } from 'react';
import type { Lang, StepId, WinkId } from '@/demo/strings';

export type DemoState = {
    lang: Lang;
    ticked: StepId[];
    hidden: boolean;
    winked: WinkId[];
    silenced: boolean;
    lastWinkAt: number;
};

export const KEY = 'demo.v1';
export const WINK_GAP_MS = 60_000;

export const DEFAULT_STATE: DemoState = {
    lang: 'de',
    ticked: [],
    hidden: false,
    winked: [],
    silenced: false,
    lastWinkAt: 0,
};

/** A wink fires only if not silenced, never seen before, and a minute has passed since the last one. */
export function canWink(state: DemoState, id: WinkId, now: number): boolean {
    return (
        !state.silenced &&
        !state.winked.includes(id) &&
        now - state.lastWinkAt >= WINK_GAP_MS
    );
}

export function withWink(state: DemoState, id: WinkId, now: number): DemoState {
    return { ...state, winked: [...state.winked, id], lastWinkAt: now };
}

export function withTick(state: DemoState, id: StepId): DemoState {
    return state.ticked.includes(id)
        ? state
        : { ...state, ticked: [...state.ticked, id] };
}

export function withUntick(state: DemoState, id: StepId): DemoState {
    return { ...state, ticked: state.ticked.filter((t) => t !== id) };
}

/** Reads stored text back into a state; anything odd falls back to the default for that field. */
export function parseState(raw: string | null): DemoState {
    if (!raw) {
        return DEFAULT_STATE;
    }

    try {
        const o = JSON.parse(raw) as Partial<DemoState>;

        return {
            lang: o.lang === 'en' ? 'en' : 'de',
            ticked: Array.isArray(o.ticked) ? o.ticked : [],
            hidden: o.hidden === true,
            winked: Array.isArray(o.winked) ? o.winked : [],
            silenced: o.silenced === true,
            lastWinkAt: typeof o.lastWinkAt === 'number' ? o.lastWinkAt : 0,
        };
    } catch {
        return DEFAULT_STATE;
    }
}

function readStored(): string | null {
    try {
        return window.localStorage.getItem(KEY);
    } catch {
        return null;
    }
}

function writeStored(state: DemoState): void {
    try {
        window.localStorage.setItem(KEY, JSON.stringify(state));
    } catch {
        // Private window: the state lives in memory for this page only.
    }
}

let current: DemoState | null = null;
const listeners = new Set<() => void>();

export function getState(): DemoState {
    current ??= parseState(readStored());

    return current;
}

export function setState(next: DemoState): void {
    current = next;
    writeStored(next);
    listeners.forEach((l) => l());
}

/** Forgets the memory copy so the next read goes to storage again (tests, and nothing else). */
export function resetMemory(): void {
    current = null;
    listeners.forEach((l) => l());
}

export function subscribe(listener: () => void): () => void {
    listeners.add(listener);

    return () => listeners.delete(listener);
}

export function useDemoState(): DemoState {
    return useSyncExternalStore(subscribe, getState, () => DEFAULT_STATE);
}

export const setLang = (lang: Lang) => setState({ ...getState(), lang });
export const tick = (id: StepId) => setState(withTick(getState(), id));
export const untick = (id: StepId) => setState(withUntick(getState(), id));
export const setHidden = (hidden: boolean) =>
    setState({ ...getState(), hidden });
export const silence = () => setState({ ...getState(), silenced: true });

/** Marks a wink as shown now; returns false (and changes nothing) when it may not fire. */
export function claimWink(id: WinkId, now: number): boolean {
    if (!canWink(getState(), id, now)) {
        return false;
    }

    setState(withWink(getState(), id, now));

    return true;
}
