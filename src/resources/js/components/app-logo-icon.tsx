import type { SVGAttributes } from 'react';

/**
 * The mark of Better NotebookLM (mark-3C): two brackets around three small squares, the last one the signal. It draws in
 * currentColor; the signal square is sky blue, lighter on a dark tile (the logo tile is ink in light mode, paper in dark).
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={2.5}
            strokeLinecap="round"
            strokeLinejoin="round"
            role="img"
            aria-label="Better NotebookLM"
            {...props}
        >
            <title>Better NotebookLM</title>
            <path d="M8.5 5H5v14h3.5 M15.5 5H19v14h-3.5" fill="none" />
            <rect
                x="7.25"
                y="7.25"
                width="3.75"
                height="3.75"
                rx="0.75"
                fill="currentColor"
                stroke="none"
            />
            <rect
                x="7.25"
                y="13"
                width="3.75"
                height="3.75"
                rx="0.75"
                fill="currentColor"
                stroke="none"
            />
            <rect
                x="13"
                y="13"
                width="3.75"
                height="3.75"
                rx="0.75"
                stroke="none"
                className="fill-sky-400 dark:fill-sky-600"
            />
        </svg>
    );
}
