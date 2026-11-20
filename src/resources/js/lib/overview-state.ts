/** The notebook's opening summary as the server returns it; `status` tells whether it is still being written or could not be (Argus gap 4). */
export type Overview = {
    summary: string | null;
    questions: string[];
    /** null: never asked. While building or failed, summary and questions are those of the last good overview. */
    status?: 'building' | 'ready' | 'failed' | null;
};

export type OverviewView = {
    state: 'building' | 'failed' | 'ready' | 'none';
    text: string;
};

export const NO_OVERVIEW = 'Your sources are ready. Ask a question to begin.';

/** What the chat shows in place of the overview: the summary, "being written", "could not be made", or the plain greeting when there is truly none. */
export function overviewView(
    overview: Overview | null,
    loadFailed = false,
): OverviewView {
    if (loadFailed || overview?.status === 'failed') {
        return { state: 'failed', text: 'The overview could not be made.' };
    }

    if (overview?.status === 'building') {
        return { state: 'building', text: 'Writing the overview…' };
    }

    if (overview?.summary) {
        return { state: 'ready', text: overview.summary };
    }

    return { state: 'none', text: NO_OVERVIEW };
}
