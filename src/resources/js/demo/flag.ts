/** Who sees the reviewer UI (D-059): reviewers always, the owner only while previewing, members never. */

const PREVIEW_KEY = 'demo.preview';

type Who = { role?: unknown } | null | undefined;

/** sessionStorage, or null where it is blocked (private windows, tests). */
function store(): Storage | null {
    try {
        return typeof window === 'undefined' ? null : window.sessionStorage;
    } catch {
        return null;
    }
}

/** `?demo=reviewer` turns the preview on, `?demo=off` turns it off; anything else keeps what was remembered. */
export function previewOn(
    search: string,
    storage: Storage | null = store(),
): boolean {
    const asked = new URLSearchParams(search).get('demo');

    try {
        if (asked === 'reviewer') {
            storage?.setItem(PREVIEW_KEY, '1');
            return true;
        }

        if (asked === 'off') {
            storage?.removeItem(PREVIEW_KEY);
            return false;
        }

        return storage?.getItem(PREVIEW_KEY) === '1';
    } catch {
        return asked === 'reviewer';
    }
}

export function isDemoGuest(
    user: Who,
    search: string,
    storage: Storage | null = store(),
): boolean {
    if (user?.role === 'reviewer') {
        return true;
    }

    if (user?.role === 'owner') {
        return previewOn(search, storage);
    }

    return false;
}
