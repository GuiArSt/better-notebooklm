/** An original drawing: a tentacled, winged silhouette beside a black cube. Uses currentColor, so it reads in light and dark. */
export function CthulhuCube({ className }: { className?: string }) {
    return (
        <svg
            viewBox="0 0 400 260"
            role="img"
            aria-label="A tentacled, winged silhouette beside a black cube"
            className={className}
        >
            {/* the stars */}
            <g fill="currentColor" opacity="0.5">
                <circle cx="30" cy="30" r="1.6" />
                <circle cx="95" cy="18" r="1.2" />
                <circle cx="170" cy="40" r="1.4" />
                <circle cx="250" cy="22" r="1.2" />
                <circle cx="345" cy="38" r="1.6" />
                <circle cx="372" cy="95" r="1.2" />
                <circle cx="20" cy="110" r="1.2" />
            </g>
            {/* the figure: wings, head, body, then the tentacles as curling strokes */}
            <g fill="currentColor">
                <path d="M120 130 C70 90 40 70 14 84 C40 96 52 112 58 136 C70 124 92 126 120 138 Z" />
                <path d="M180 130 C230 90 260 70 286 84 C260 96 248 112 242 136 C230 124 208 126 180 138 Z" />
                <ellipse cx="150" cy="92" rx="32" ry="36" />
                <path d="M112 140 C100 176 112 216 150 216 C188 216 200 176 188 140 C170 150 130 150 112 140 Z" />
            </g>
            <g
                fill="none"
                stroke="currentColor"
                strokeWidth="7"
                strokeLinecap="round"
            >
                <path d="M128 118 C112 138 96 138 90 160 C86 176 98 184 106 176" />
                <path d="M138 124 C130 150 118 160 122 186 C124 198 134 200 138 192" />
                <path d="M150 126 C150 150 146 166 152 184" />
                <path d="M162 124 C170 150 182 160 178 186 C176 198 166 200 162 192" />
                <path d="M172 118 C188 138 204 138 210 160 C214 176 202 184 194 176" />
            </g>
            {/* the eyes, cut from the head */}
            <g fill="var(--background, #fff)">
                <ellipse cx="139" cy="88" rx="5" ry="3.2" />
                <ellipse cx="161" cy="88" rx="5" ry="3.2" />
            </g>
            {/* the black cube, with a faint edge so it shows on a dark page */}
            <g>
                <path
                    d="M300 150 L350 170 L350 230 L300 210 Z"
                    fill="#000"
                    stroke="currentColor"
                    strokeWidth="1.5"
                    strokeLinejoin="round"
                />
                <path
                    d="M350 170 L385 150 L385 210 L350 230 Z"
                    fill="#0a0a0a"
                    stroke="currentColor"
                    strokeWidth="1.5"
                    strokeLinejoin="round"
                />
                <path
                    d="M300 150 L335 130 L385 150 L350 170 Z"
                    fill="#161616"
                    stroke="currentColor"
                    strokeWidth="1.5"
                    strokeLinejoin="round"
                />
            </g>
            <path
                d="M20 238 H380"
                stroke="currentColor"
                strokeWidth="1"
                opacity="0.4"
            />
        </svg>
    );
}
