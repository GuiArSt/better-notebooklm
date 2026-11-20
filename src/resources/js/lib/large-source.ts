import { usd } from '@/lib/money';
import type { Source } from '@/types/app';

/** The warning for a source being read that is over the large limit, in the server's numbers; null for any other. */
export function largeNotice(
    s: Pick<
        Source,
        'large' | 'status' | 'page_count' | 'estimated_cost_micro_usd'
    >,
): string | null {
    if (!s.large || s.status !== 'pending' || s.page_count === null) {
        return null;
    }

    const cost =
        s.estimated_cost_micro_usd === null
            ? ''
            : ` and uses about ${usd(s.estimated_cost_micro_usd / 1_000_000)} of your budget`;

    return `Large source: about ${s.page_count} pages. Reading it takes a few minutes${cost}.`;
}
