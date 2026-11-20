import type { StudioItem } from '@/types/studio';

/** What to tell the user about the server's check of an infographic (D-057); null when it passed, or when nothing was recorded. */
export type ReviewNote =
    | { verdict: 'bad'; heading: string; problems: string[]; short: string }
    | { verdict: 'unavailable'; text: string; short: string };

export function reviewNote(
    item: Pick<StudioItem, 'kind' | 'settings'>,
): ReviewNote | null {
    const review = item.settings?.review;

    if (item.kind !== 'infographic' || !review) {
        return null;
    }

    if (review.verdict === 'bad') {
        return {
            verdict: 'bad',
            heading: 'The check found problems in this picture:',
            problems: review.problems ?? [],
            short: 'The check found problems',
        };
    }

    if (review.verdict === 'unavailable') {
        const text = 'Not checked: the review didn’t answer';

        return { verdict: 'unavailable', text, short: text };
    }

    return null;
}
