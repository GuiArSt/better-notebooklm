export type FitRange = { min?: number; max?: number; step?: number };

/**
 * The largest scale, in steps from `max` down to `min`, at which `overflowsAt` is false; `min` when none fits.
 * Pure: the caller says what "overflows" means (a layout measurement), so this runs and tests with no DOM.
 */
export function fitScale(
    overflowsAt: (scale: number) => boolean,
    { min = 0.6, max = 1, step = 0.04 }: FitRange = {},
): number {
    const round = (n: number) => Math.round(n * 1000) / 1000;

    for (let scale = max; scale > min + 1e-9; scale = round(scale - step)) {
        if (!overflowsAt(scale)) {
            return scale;
        }
    }

    return min;
}
