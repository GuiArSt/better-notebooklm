export type Usage = {
    tier: string;
    label: string;
    spent_usd: number;
    cap_usd: number;
    percent: number;
    resets_at: string;
};

export type Source = {
    id: number;
    title: string;
    kind: string; // text, pdf, web or note
    status: 'pending' | 'ready' | 'failed';
    checked: boolean;
    error: string | null;
    origin_url: string | null;
    /** D-040, from the server: a source over the large limit, with its page count and the most reading it can cost. */
    large: boolean;
    page_count: number | null;
    page_count_estimated: boolean;
    estimated_cost_micro_usd: number | null;
};

export type Citation = {
    id: number;
    source_id: number;
    source_title: string;
    page: number | null;
    start: number;
    end: number;
    quote: string;
};

export type Note = {
    id: number;
    title: string;
    content: string;
    citations: Citation[] | null;
    from_chat: boolean;
    checked: boolean;
    updated_at: string;
};

export type Turn = {
    question: string;
    answer: string;
    citations: Citation[] | null; // null while streaming
    error: string | null;
    saved?: boolean; // saved to a note, kept with the turn
    asked_at?: string; // when it was asked (ISO), for the day header
};

export type NotebookSummary = {
    id: number;
    title: string;
    emoji: string;
    icon_status?: string | null;
};

/** The place a citation points to: which source, and which characters. */
export type Passage = {
    sourceId: number;
    start: number;
    end: number;
    page: number | null;
};
