import type { ReactNode, SVGAttributes } from 'react';

/**
 * One coloured icon per kind of source (D-044), in the Studio set's grammar: a 24-unit grid, a 1.75 stroke,
 * currentColor, and the mark's agent square as the one filled detail. The tint is the kind's own colour.
 */
export type SourceKind = 'pdf' | 'text' | 'web' | 'note' | 'audio';

const agent = (x: number, y: number, size = 2.5) => (
    <rect
        x={x}
        y={y}
        width={size}
        height={size}
        rx="0.6"
        fill="currentColor"
        stroke="none"
    />
);

export const SOURCE_KINDS: Record<
    SourceKind,
    { label: string; tint: string; draw: ReactNode }
> = {
    pdf: {
        label: 'PDF',
        tint: 'text-red-600 dark:text-red-400',
        draw: (
            <>
                <path d="M14 3H6.5A2.5 2.5 0 0 0 4 5.5v13A2.5 2.5 0 0 0 6.5 21h11a2.5 2.5 0 0 0 2.5-2.5V9z M14 3v6h6" />
                <rect
                    x="6.5"
                    y="13"
                    width="9"
                    height="4.5"
                    rx="1"
                    fill="currentColor"
                    stroke="none"
                />
            </>
        ),
    },
    text: {
        label: 'Pasted text',
        tint: 'text-violet-600 dark:text-violet-400',
        draw: (
            <>
                <rect x="4" y="3" width="16" height="18" rx="2.5" />
                <path d="M11 8.5h5.5 M7.5 12.5h9 M7.5 16.5h6" />
                {agent(7.25, 7.25)}
            </>
        ),
    },
    web: {
        label: 'Web page',
        tint: 'text-sky-600 dark:text-sky-400',
        draw: (
            <>
                <rect x="3" y="4" width="18" height="16" rx="2.5" />
                <path d="M3 8.75h18" />
                <path d="M9.5 16.5a3.25 3.25 0 0 1 0-4.6l1.4-1.4a3.25 3.25 0 0 1 4.6 4.6l-.7.7" />
                {agent(5, 5.4, 2)}
            </>
        ),
    },
    note: {
        label: 'Note',
        tint: 'text-amber-600 dark:text-amber-400',
        draw: (
            <>
                <path d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5V14l-6 6H6.5A2.5 2.5 0 0 1 4 17.5z M14 20v-3.5a2.5 2.5 0 0 1 2.5-2.5H20" />
                <path d="M7.5 9h9 M7.5 12.5h4.5" />
            </>
        ),
    },
    audio: {
        label: 'Audio',
        tint: 'text-emerald-600 dark:text-emerald-400',
        draw: (
            <>
                <path d="M4.5 10v4 M8 7v10 M11.5 4v16 M15 8v8" />
                {agent(17.75, 10.75)}
            </>
        ),
    },
};

/** Any kind the server sends that we have no icon for draws as pasted text, labelled plainly. */
export function sourceKind(kind: string): SourceKind {
    return kind in SOURCE_KINDS ? (kind as SourceKind) : 'text';
}

/** The icon for a source of this kind, coloured, with a label for screen readers. */
export function SourceIcon({
    kind,
    className = 'size-4 shrink-0',
    ...props
}: { kind: string } & SVGAttributes<SVGElement>) {
    const known = kind in SOURCE_KINDS;
    const { label, tint, draw } = SOURCE_KINDS[sourceKind(kind)];

    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.75}
            strokeLinecap="round"
            strokeLinejoin="round"
            role="img"
            aria-label={known ? label : 'Source'}
            data-kind={known ? kind : 'unknown'}
            className={`${tint} ${className}`}
            {...props}
        >
            {draw}
        </svg>
    );
}
