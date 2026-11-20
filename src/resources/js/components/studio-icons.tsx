import type { SVGAttributes } from 'react';

// The nine Studio icons: our own set (docs/assets/final/studio). They draw in currentColor, so a tile's text colour tints them.
type Props = SVGAttributes<SVGElement>;

function Svg({ children, ...props }: Props) {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.75}
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            {...props}
        >
            {children}
        </svg>
    );
}

export function AudioIcon(props: Props) {
    return (
        <Svg {...props}>
            <path d="M3 5.5A2.5 2.5 0 0 1 5.5 3h6A2.5 2.5 0 0 1 14 5.5v3a2.5 2.5 0 0 1-2.5 2.5H7.5l-3 2.5z" />
            <path d="M10 15.5a2.5 2.5 0 0 1 2.5-2.5h6a2.5 2.5 0 0 1 2.5 2.5v3a2.5 2.5 0 0 1-2.5 2.5h-.5v2.5L15 21h-2.5a2.5 2.5 0 0 1-2.5-2.5z" />
            <rect
                x="6"
                y="5.75"
                width="2.25"
                height="2.25"
                rx="0.6"
                fill="currentColor"
                stroke="none"
            />
            <rect
                x="9.25"
                y="5.75"
                width="2.25"
                height="2.25"
                rx="0.6"
                fill="currentColor"
                stroke="none"
            />
            <rect
                x="13"
                y="15.75"
                width="2.25"
                height="2.25"
                rx="0.6"
                fill="currentColor"
                stroke="none"
            />
            <rect
                x="16.25"
                y="15.75"
                width="2.25"
                height="2.25"
                rx="0.6"
                fill="currentColor"
                stroke="none"
            />
        </Svg>
    );
}

export function VideoIcon(props: Props) {
    return (
        <Svg {...props}>
            <rect x="3" y="4" width="18" height="13" rx="2.5" />
            <path d="M10.25 8v5l4.25-2.5z" />
            <path d="M12 17v3.5 M8.5 21h7" />
        </Svg>
    );
}

export function MindmapIcon(props: Props) {
    return (
        <Svg {...props}>
            <rect
                x="3"
                y="10.25"
                width="3.5"
                height="3.5"
                rx="0.6"
                fill="currentColor"
                stroke="none"
            />
            <rect x="15" y="3.5" width="6" height="3.5" rx="1.75" />
            <rect x="15" y="10.25" width="6" height="3.5" rx="1.75" />
            <rect x="15" y="17" width="6" height="3.5" rx="1.75" />
            <path d="M6.5 12C10.5 12 10.5 5.25 15 5.25 M6.5 12H15 M6.5 12C10.5 12 10.5 18.75 15 18.75" />
        </Svg>
    );
}

export function ReportsIcon(props: Props) {
    return (
        <Svg {...props}>
            <path d="M14 3H6.5A2.5 2.5 0 0 0 4 5.5v13A2.5 2.5 0 0 0 6.5 21h11a2.5 2.5 0 0 0 2.5-2.5V9z M14 3v6h6" />
            <path d="M7.5 13h9 M7.5 16.5h5" />
            <rect
                x="14"
                y="15.25"
                width="2.5"
                height="2.5"
                rx="0.6"
                fill="currentColor"
                stroke="none"
            />
        </Svg>
    );
}

export function FlashcardsIcon(props: Props) {
    return (
        <Svg {...props}>
            <path d="M7 7V5.5A2.5 2.5 0 0 1 9.5 3h9A2.5 2.5 0 0 1 21 5.5v8a2.5 2.5 0 0 1-2.5 2.5H17" />
            <rect x="3" y="7.5" width="14" height="13.5" rx="2.5" />
            <path d="M8.25 12a1.75 1.75 0 1 1 2.6 1.55c-.55.3-.85.7-.85 1.2v.25" />
            <rect
                x="9.1"
                y="17"
                width="1.8"
                height="1.8"
                rx="0.4"
                fill="currentColor"
                stroke="none"
            />
        </Svg>
    );
}

export function QuizIcon(props: Props) {
    return (
        <Svg {...props}>
            <rect x="3.5" y="3.75" width="3.5" height="3.5" rx="0.8" />
            <rect
                x="3.5"
                y="10.25"
                width="3.5"
                height="3.5"
                rx="0.8"
                fill="currentColor"
                stroke="none"
            />
            <rect x="3.5" y="16.75" width="3.5" height="3.5" rx="0.8" />
            <path d="M10.5 5.5H20 M10.5 12H18 M10.5 18.5H19" />
        </Svg>
    );
}

export function InfographicIcon(props: Props) {
    return (
        <Svg {...props}>
            <rect x="5" y="2.5" width="14" height="19" rx="2.5" />
            <path d="M8.5 11V8.5 M12 11V5.5 M15.5 11V7.5" />
            <rect
                x="8"
                y="14"
                width="2.5"
                height="2.5"
                rx="0.6"
                fill="currentColor"
                stroke="none"
            />
            <rect
                x="11"
                y="14"
                width="2.5"
                height="2.5"
                rx="0.6"
                fill="currentColor"
                stroke="none"
            />
            <path d="M8.25 18.5h7.5" />
        </Svg>
    );
}

export function SlidesIcon(props: Props) {
    return (
        <Svg {...props}>
            <path d="M7 8V6.5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2V14a2 2 0 0 1-2 2h-2" />
            <rect x="3" y="8" width="14" height="11.5" rx="2.5" />
            <rect
                x="6"
                y="11"
                width="2.5"
                height="2.5"
                rx="0.6"
                fill="currentColor"
                stroke="none"
            />
            <path d="M10 12.25h4 M6 16h8" />
        </Svg>
    );
}

export function TableIcon(props: Props) {
    return (
        <Svg {...props}>
            <rect x="3" y="4" width="18" height="16" rx="2.5" />
            <path d="M3 9.5h18 M3 14.75h18 M9 9.5V20 M15 9.5V20" />
            <rect
                x="16.75"
                y="11"
                width="2.5"
                height="2.5"
                rx="0.6"
                fill="currentColor"
                stroke="none"
            />
        </Svg>
    );
}
