/** Each Studio tile has one identity: its icon and one tint. Everything it makes (the row, the viewer header) wears the same. */
export type TileKey =
    | 'audio'
    | 'video'
    | 'mindmap'
    | 'reports'
    | 'flashcards'
    | 'quiz'
    | 'infographic'
    | 'slides'
    | 'table';

export const TILE_TINTS: Record<TileKey, { text: string; soft: string }> = {
    audio: {
        text: 'text-blue-600 dark:text-blue-400',
        soft: 'bg-blue-100 dark:bg-blue-500/20',
    },
    video: {
        text: 'text-emerald-600 dark:text-emerald-400',
        soft: 'bg-emerald-100 dark:bg-emerald-500/20',
    },
    mindmap: {
        text: 'text-indigo-600 dark:text-indigo-400',
        soft: 'bg-indigo-100 dark:bg-indigo-500/20',
    },
    reports: {
        text: 'text-fuchsia-600 dark:text-fuchsia-400',
        soft: 'bg-fuchsia-100 dark:bg-fuchsia-500/20',
    },
    flashcards: {
        text: 'text-orange-600 dark:text-orange-400',
        soft: 'bg-orange-100 dark:bg-orange-500/20',
    },
    quiz: {
        text: 'text-teal-600 dark:text-teal-400',
        soft: 'bg-teal-100 dark:bg-teal-500/20',
    },
    infographic: {
        text: 'text-amber-600 dark:text-amber-400',
        soft: 'bg-amber-100 dark:bg-amber-500/20',
    },
    slides: {
        text: 'text-rose-600 dark:text-rose-400',
        soft: 'bg-rose-100 dark:bg-rose-500/20',
    },
    table: {
        text: 'text-sky-600 dark:text-sky-400',
        soft: 'bg-sky-100 dark:bg-sky-500/20',
    },
};

/** The tile that makes each kind of item: the three written kinds all come from Reports. */
export const TILE_OF_KIND: Record<string, TileKey> = {
    audio: 'audio',
    briefing: 'reports',
    study_guide: 'reports',
    faq: 'reports',
    mindmap: 'mindmap',
    quiz: 'quiz',
    slides: 'slides',
    infographic: 'infographic',
};
