import { usePage } from '@inertiajs/react';
import { isDemoGuest } from '@/demo/flag';
import { setHidden, setLang, tick, untick, useDemoState } from '@/demo/state';

type Shared = { auth?: { user?: { role?: unknown; name?: string } | null } };

export function useDemo() {
    const user = usePage<Shared>().props.auth?.user;
    const state = useDemoState();
    const guest = isDemoGuest(
        user,
        typeof window === 'undefined' ? '' : window.location.search,
    );

    return {
        guest,
        name: user?.name ?? '',
        lang: state.lang,
        setLang,
        ticked: state.ticked,
        tick,
        untick,
        hidden: state.hidden,
        setHidden,
    };
}
