import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { isDemoGuest } from '@/demo/flag';
import { claimWink, getState, silence, tick } from '@/demo/state';
import { SILENCE, WINKS } from '@/demo/strings';
import type { WinkId } from '@/demo/strings';

/** Never joke over an error: the app's own alert owns the screen. */
export function errorShown(doc: Document): boolean {
    return doc.querySelector('[role="alert"]') !== null;
}

/** A wink that cannot fire now is dropped, not queued. */
export function wink(id: WinkId): void {
    if (errorShown(document) || !claimWink(id, Date.now())) {
        return;
    }

    const lang = getState().lang;

    toast(WINKS[id][lang], {
        duration: 6000,
        action: { label: SILENCE[lang], onClick: silence },
    });
}

export function isGreyed(el: Element): boolean {
    const grey = el.closest('[aria-disabled="true"]');

    return (
        grey !== null && (grey.textContent ?? '').includes('not in this demo')
    );
}

/** The element -> wink pairs the observer watches: the first one to appear fires its wink. */
const APPEARS: Array<[string, WinkId]> = [
    ['[data-testid=citation]', 'firstAnswer'],
    ['[data-testid=quiz]', 'quiz'],
    ['[data-testid=audio-player]', 'podcast'],
];

// The Studio tile last clicked: the Customize dialog does not know its kind, the tile does (studio-panel.tsx).
let lastTile = '';

function onClick(e: MouseEvent): void {
    const t = e.target instanceof Element ? e.target : null;

    if (!t) {
        return;
    }

    if (t.closest('[data-testid=site-footer] a[target=_blank]')) {
        wink('impressum');
    }

    if (t.closest('[data-testid=featured]')) {
        wink('featured');
    }

    if (t.closest('[data-testid=usage-meter]')) {
        wink('meter');
    }

    if (isGreyed(t)) {
        wink('greyed');
    }

    const tile = t.closest('[data-testid^=tile-]');

    if (tile) {
        lastTile = tile.getAttribute('data-testid') ?? '';
    }

    if (
        t.closest('[data-testid=customize-generate]') &&
        lastTile === 'tile-audio'
    ) {
        tick('podcast');
    }
}

function onOver(e: MouseEvent): void {
    if (
        e.target instanceof Element &&
        e.target.closest('[data-testid=citation]')
    ) {
        tick('cite');
        wink('citation');
    }
}

// The chat form sits beside [data-testid=chat], not inside it: it is the form with the "Question" input.
function onSubmit(e: Event): void {
    const form = e.target instanceof Element ? e.target : null;

    if (form?.querySelector('input[aria-label=Question]')) {
        tick('ask');
    }

    if (form?.querySelector('[data-testid=add-submit]')) {
        tick('source');
    }
}

function presence(): Record<string, boolean> {
    return Object.fromEntries(
        APPEARS.map(([sel]) => [sel, document.querySelector(sel) !== null]),
    );
}

function onPage(path: string): void {
    if (path === '/privacy') {
        wink('privacy');
    }

    if (path === '/pricing') {
        tick('pricing');
        wink('pricing');
    }
}

function lateNight(hour: number): boolean {
    return hour >= 22 || hour < 5;
}

type Shared = { auth?: { user?: { role?: unknown } | null } };

/**
 * Mounts once in app.tsx, beside the Toaster: outside the Inertia page, where usePage() throws. So it takes the first page from
 * withApp and follows every later visit through the router's navigate event. Renders nothing; for a reviewer it fires the winks.
 */
export function DemoLayer({ page }: { page: { props: unknown } }) {
    const [user, setUser] = useState((page.props as Shared).auth?.user);
    const guest = isDemoGuest(user, window.location.search);

    useEffect(
        () =>
            router.on('navigate', (event) =>
                setUser((event.detail.page.props as Shared).auth?.user),
            ),
        [],
    );

    useEffect(() => {
        if (!guest) {
            return;
        }

        const seen = presence();
        const watcher = new MutationObserver(() => {
            for (const [sel, id] of APPEARS) {
                const now = document.querySelector(sel) !== null;

                if (now && !seen[sel]) {
                    wink(id);

                    // Listening to a podcast counts as step 4: an invited reviewer's showcase already has one.
                    if (id === 'podcast') {
                        tick('podcast');
                    }
                }

                seen[sel] = now;
            }
        });

        document.addEventListener('click', onClick, true);
        document.addEventListener('mouseover', onOver);
        document.addEventListener('submit', onSubmit, true);
        watcher.observe(document.body, { childList: true, subtree: true });
        const off = router.on('navigate', (event) =>
            onPage(
                new URL(event.detail.page.url, window.location.origin).pathname,
            ),
        );

        onPage(window.location.pathname);

        if (lateNight(new Date().getHours())) {
            wink('lateNight');
        }

        return () => {
            document.removeEventListener('click', onClick, true);
            document.removeEventListener('mouseover', onOver);
            document.removeEventListener('submit', onSubmit, true);
            watcher.disconnect();
            off();
        };
    }, [guest]);

    return null;
}
