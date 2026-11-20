import type { Citation } from '@/types/app';

export type MindNode = {
    label: string;
    refs: number[];
    children: MindNode[];
};

/** How an item was made, recorded when it was created (null for items made before that). */
export type ItemSettings = {
    kind?: string;
    sources?: { id: number; title: string }[] | 'all';
    language?: string;
    instructions?: string | null;
    focus?: string | null;
    options?: Record<string, string>;
    /** Audio only: the format's and host's display names, written by the server when the item was made. */
    podcast?: {
        format: string;
        persona: { name: string; role: string } | null;
    };
    tier?: string;
    models?: {
        text?: { provider: string; model: string };
        image?: {
            provider: string;
            model: string;
            size: string;
            quality: string;
        };
        speech?: {
            provider: string;
            model: string;
            voices?: Record<string, string>;
        };
    };
    /** Infographics: the choices in words, and the picture's model. */
    infographic?: { orientation: string; style: string; detail: string };
    /** The server's check of a drawn infographic (D-057): problems are listed only when the verdict is bad. */
    review?: {
        verdict: 'good' | 'bad' | 'unavailable';
        attempts: number;
        problems?: string[];
    };
    cost_micro_usd?: number;
    duration_seconds?: number;
    regenerated_from?: number;
};

/** One multiple-choice question: four options, the right one, an explanation and the passages behind it. */
export type QuizQuestion = {
    question: string;
    options: string[];
    answer: number | string;
    explanation: string;
    hint?: string | null;
    refs?: number[];
};

export type StudioKind =
    | 'audio'
    | 'briefing'
    | 'study_guide'
    | 'faq'
    | 'mindmap'
    | 'quiz'
    | 'slides'
    | 'infographic';

/** One generated item, as the Studio backend returns it. */
export type StudioItem = {
    id: number;
    notebook_id: number;
    kind: StudioKind;
    title: string;
    status: 'pending' | 'running' | 'done' | 'failed';
    step: string | null;
    step_n: number | null;
    step_total: number | null;
    tree: { title: string; children: MindNode[] } | null;
    content: string | null;
    citations: Citation[] | null;
    script: { speaker: 'A' | 'B'; text: string }[] | null;
    quiz?: QuizQuestion[] | null;
    slides?: unknown;
    /** Infographics: the picture (done only) and the brief it was drawn from, each point with the passages behind it. */
    image_url?: string | null;
    infographic?: {
        title: string;
        points: {
            heading: string;
            figure: string | null;
            text: string;
            refs: string[];
        }[];
    } | null;
    audio_url: string | null;
    error: string | null;
    settings?: ItemSettings | null;
    sources_count?: number | null;
    duration_seconds?: number | null;
    created_at: string;
};
