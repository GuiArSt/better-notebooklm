import type { ReactNode, SVGAttributes } from 'react';

/**
 * Drawn icons for a notebook (D-059), replacing the emoji. The stored value is still the emoji (the database, the
 * picker and the API keep it), so each emoji is a key to one drawing in our grammar: a 24-unit grid, a 1.75 stroke,
 * currentColor, and the mark's agent square as the one filled detail, in --glyph-signal when the page sets it.
 */

const sig = 'var(--glyph-signal, currentColor)';

const agent = (x: number, y: number, size = 2.5, signal = false) => (
    <rect
        x={x}
        y={y}
        width={size}
        height={size}
        rx={size * 0.24}
        fill={signal ? sig : 'currentColor'}
        stroke="none"
    />
);

export const DEFAULT_EMOJI = '📓';

/** Every emoji the picker offers, in its order, with the topic it stands for and its drawing. */
export const NOTEBOOK_GLYPHS: Record<
    string,
    { label: string; draw: ReactNode }
> = {
    '📓': {
        label: 'Notebook',
        draw: (
            <>
                <rect x="5" y="3" width="14" height="18" rx="2.5" />
                <path d="M8.5 3v18 M11.5 8h4.5 M11.5 11.5h3" />
                {agent(11.5, 14.5, 2.5, true)}
            </>
        ),
    },
    '📚': {
        label: 'Books',
        draw: (
            <>
                <rect x="4" y="4" width="4" height="15.5" rx="1" />
                <rect x="9.5" y="6.5" width="4" height="13" rx="1" />
                <path d="M15.2 7.4l3.3-.9 3 12.3-3.3.9z M3 21h18" />
                {agent(4.75, 7, 2.5, true)}
            </>
        ),
    },
    '🔬': {
        label: 'Science',
        draw: (
            <>
                <rect x="9.5" y="3" width="4" height="8.5" rx="1.2" />
                <path d="M11.5 11.5v2.5 M8 15.5h7 M15.5 8.5a6 6 0 0 1 0 11.5 M5 21h14" />
                {agent(10.25, 4.75, 2.5, true)}
            </>
        ),
    },
    '🧪': {
        label: 'Lab',
        draw: (
            <>
                <path d="M9 3h6 M10.5 3v6.2L5.6 18.4A1.8 1.8 0 0 0 7.2 21h9.6a1.8 1.8 0 0 0 1.6-2.6L13.5 9.2V3 M7.6 15h8.8" />
                {agent(10.75, 16.75, 2.5, true)}
            </>
        ),
    },
    '💡': {
        label: 'Ideas',
        draw: (
            <>
                <path d="M9.5 18h5 M10.5 21h3 M12 3a6 6 0 0 0-3.4 10.9c.6.5.9 1.1.9 1.9v.2h5v-.2c0-.8.3-1.4.9-1.9A6 6 0 0 0 12 3z" />
                {agent(10.75, 8.75, 2.5, true)}
            </>
        ),
    },
    '🎓': {
        label: 'Study',
        draw: (
            <>
                <path d="M2.5 9.5 12 5l9.5 4.5L12 14z M6.5 11.6v4.4c0 1.4 2.5 2.5 5.5 2.5s5.5-1.1 5.5-2.5v-4.4 M21.5 9.5v5" />
                {agent(20.25, 15, 2.5, true)}
            </>
        ),
    },
    '📰': {
        label: 'News',
        draw: (
            <>
                <path d="M17.5 8h3v9.5a2 2 0 0 1-2 2H5.5a2.5 2.5 0 0 1-2.5-2.5V6.5A1.5 1.5 0 0 1 4.5 5h11.5a1.5 1.5 0 0 1 1.5 1.5v11a2 2 0 0 0 1 1.75" />
                <path d="M11.5 9h3 M11.5 12h3 M6.5 15.5h8" />
                {agent(6.25, 8.25, 4, true)}
            </>
        ),
    },
    '🌍': {
        label: 'World',
        draw: (
            <>
                <circle cx="12" cy="12" r="9" />
                <path d="M3 12h18 M12 3c2.5 2.6 3.8 5.6 3.8 9s-1.3 6.4-3.8 9c-2.5-2.6-3.8-5.6-3.8-9S9.5 5.6 12 3z" />
                {agent(15.5, 6.5, 2.5, true)}
            </>
        ),
    },
    '⚖️': {
        label: 'Law',
        draw: (
            <>
                <path d="M12 5.5V20 M8 20.5h8 M5 7h14 M5 7l-2.8 6.2a2.9 2.9 0 0 0 5.6 0z M19 7l-2.8 6.2a2.9 2.9 0 0 0 5.6 0z" />
                {agent(10.75, 2.5, 2.5, true)}
            </>
        ),
    },
    '💼': {
        label: 'Work',
        draw: (
            <>
                <rect x="3" y="7" width="18" height="13" rx="2.5" />
                <path d="M9 7V5.5A1.5 1.5 0 0 1 10.5 4h3A1.5 1.5 0 0 1 15 5.5V7 M3 12.5h7.5 M13.5 12.5H21" />
                {agent(10.75, 11.25, 2.5, true)}
            </>
        ),
    },
    '🧠': {
        label: 'Mind',
        draw: (
            <>
                <path d="M12 5.5a3 3 0 0 0-5.4-1.8A3 3 0 0 0 4.4 8a3.5 3.5 0 0 0 .4 6.1 3 3 0 0 0 3 4.4A3 3 0 0 0 12 19z" />
                <path d="M12 5.5a3 3 0 0 1 5.4-1.8A3 3 0 0 1 19.6 8a3.5 3.5 0 0 1-.4 6.1 3 3 0 0 1-3 4.4A3 3 0 0 1 12 19z" />
                <path d="M12 5.5V19" />
                {agent(14.5, 10, 2.5, true)}
            </>
        ),
    },
    '🎨': {
        label: 'Art',
        draw: (
            <>
                <path d="M12 3a9 9 0 0 0 0 18c1.4 0 2.1-.9 2.1-2 0-1.5-1.2-1.6-1.2-3 0-1 .8-1.8 1.8-1.8H17a4 4 0 0 0 4-4C21 6.6 17 3 12 3z" />
                {agent(6.4, 10.2, 2.3)}
                {agent(9.2, 6.2, 2.3)}
                {agent(13.8, 6.2, 2.3, true)}
            </>
        ),
    },
    '🎵': {
        label: 'Music',
        draw: (
            <>
                <path d="M9 18V5.6l11-2.1V16 M9 9.6l11-2.1" />
                <circle cx="6.5" cy="18" r="2.5" />
                <circle cx="17.5" cy="16" r="2.5" />
                {agent(16.25, 14.75, 2.5, true)}
            </>
        ),
    },
    '🏛️': {
        label: 'History',
        draw: (
            <>
                <path d="M3 9.5 12 4l9 5.5z M4 20.5h16 M6 12v6 M10 12v6 M14 12v6 M18 12v6" />
                {agent(10.75, 6.6, 2.5, true)}
            </>
        ),
    },
    '🌱': {
        label: 'Nature',
        draw: (
            <>
                <path d="M12 21v-9 M12 12C12 8 9 5.5 4.5 5.5 4.5 10 7.5 12 12 12z M12 10c0-3.6 2.6-6 7-6 0 4-2.6 6-7 6z M7.5 21h9" />
                {agent(15.6, 6.4, 2.2, true)}
            </>
        ),
    },
    '🚀': {
        label: 'Launch',
        draw: (
            <>
                <path d="M12 2.5c3 2 4.5 5.5 4.5 9.5L15 16H9l-1.5-4c0-4 1.5-7.5 4.5-9.5z M7.6 12 5 15.5l2.8.6 M16.4 12l2.6 3.5-2.8.6 M10.5 18.5 12 21.5l1.5-3" />
                {agent(10.75, 7.5, 2.5, true)}
            </>
        ),
    },
};

/** The drawing and topic for a stored emoji; an emoji we have no drawing for falls back to the notebook. */
export function notebookGlyph(emoji: string | null | undefined) {
    return NOTEBOOK_GLYPHS[emoji ?? ''] ?? NOTEBOOK_GLYPHS[DEFAULT_EMOJI];
}

/**
 * A notebook's drawn icon. `label` true gives it role="img" and the topic as its name (for a picker option); by default
 * it is decorative, because the notebook's title names it.
 */
export function NotebookGlyph({
    emoji,
    label = false,
    ...props
}: {
    emoji: string | null | undefined;
    label?: boolean;
} & SVGAttributes<SVGElement>) {
    const glyph = notebookGlyph(emoji);

    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.75}
            strokeLinecap="round"
            strokeLinejoin="round"
            {...(label
                ? { role: 'img', 'aria-label': glyph.label }
                : { 'aria-hidden': true })}
            data-glyph={glyph.label.toLowerCase()}
            {...props}
        >
            {glyph.draw}
        </svg>
    );
}
