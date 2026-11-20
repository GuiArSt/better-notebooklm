import { useLayoutEffect } from 'react';
import type { RefObject } from 'react';
import { fitScale } from '@/slides/fit/fit-scale';
import type { FitRange } from '@/slides/fit/fit-scale';

/** The property slides.css multiplies every font size by. */
export const FIT_PROPERTY = '--sl-fit';

/**
 * Too much text for the box. scrollHeight sees only overflow at the bottom and right, so text that a bottom- or
 * centre-aligned box pushes out of its top (the title slide) is caught by comparing the children's edges too.
 */
const overflows = (box: HTMLElement) => {
    if (
        box.scrollHeight > box.clientHeight + 1 ||
        box.scrollWidth > box.clientWidth + 1
    ) {
        return true;
    }
    const outer = box.getBoundingClientRect();

    return [...box.children].some((child) => {
        const r = child.getBoundingClientRect();

        return r.top < outer.top - 1 || r.left < outer.left - 1;
    });
};

/**
 * Shrinks the text inside `ref` until it fits: it steps `--sl-fit` down (fitScale) while the element, or any box inside it marked
 * `data-fit-box`, overflows. Each pass starts again from `max`, so a bigger frame grows the text back. It re-runs when the element
 * resizes, when the fonts load and before printing. Where there is no layout (jsdom, SSR) it does nothing.
 */
export function useFitText(
    ref: RefObject<HTMLElement | null>,
    { min, max = 1 }: Pick<FitRange, 'min' | 'max'> = {},
) {
    // a layout effect, so the fitted size is in place before the first paint: no flash of overflowing text
    useLayoutEffect(() => {
        const el = ref.current;
        if (!el || typeof ResizeObserver === 'undefined') {
            return;
        }

        const run = () => {
            el.style.setProperty(FIT_PROPERTY, String(max));
            if (el.clientHeight === 0) {
                return;
            }
            const boxes = [
                el,
                ...el.querySelectorAll<HTMLElement>('[data-fit-box]'),
            ];
            const scale = fitScale(
                (s) => {
                    el.style.setProperty(FIT_PROPERTY, String(s));

                    return boxes.some(overflows);
                },
                { min, max },
            );
            el.style.setProperty(FIT_PROPERTY, String(scale));
        };

        const observer = new ResizeObserver(run);
        observer.observe(el);
        let live = true;
        void document.fonts?.ready.then(() => live && run());
        window.addEventListener('beforeprint', run);
        run();

        return () => {
            live = false;
            observer.disconnect();
            window.removeEventListener('beforeprint', run);
        };
    }, [ref, min, max]);
}
