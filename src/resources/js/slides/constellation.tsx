/**
 * The constellation accent (bg-2, D-030): the mark's rounded squares joined by hairlines, one in signal blue.
 * Decorative only; drawn in currentColor so it follows light, dark and the inverted section slide.
 */

type Point = readonly [number, number];

const CORNER: { points: Point[]; links: [number, number][]; signal: number } = {
    points: [
        [40, 70],
        [130, 40],
        [210, 96],
        [300, 52],
        [372, 120],
        [250, 178],
        [150, 160],
        [330, 236],
    ],
    links: [
        [0, 1],
        [1, 2],
        [2, 3],
        [3, 4],
        [2, 5],
        [5, 6],
        [5, 7],
    ],
    signal: 3,
};

const FIELD: { points: Point[]; links: [number, number][]; signal: number } = {
    points: [
        [620, 60],
        [700, 120],
        [800, 70],
        [880, 150],
        [760, 220],
        [660, 300],
        [860, 330],
        [940, 420],
        [720, 430],
        [560, 470],
        [900, 40],
    ],
    links: [
        [0, 1],
        [1, 2],
        [2, 10],
        [2, 3],
        [3, 4],
        [4, 1],
        [4, 5],
        [4, 6],
        [6, 7],
        [6, 8],
        [8, 9],
    ],
    signal: 6,
};

export function Constellation({ variant }: { variant: 'corner' | 'field' }) {
    const shape = variant === 'corner' ? CORNER : FIELD;
    const viewBox = variant === 'corner' ? '0 0 400 280' : '0 0 1000 562.5';
    const size = variant === 'corner' ? 9 : 10;

    return (
        <svg
            className="sl-constellation"
            viewBox={viewBox}
            preserveAspectRatio={
                variant === 'corner' ? 'xMaxYMin meet' : 'xMaxYMid slice'
            }
            aria-hidden="true"
            focusable="false"
        >
            <g
                stroke="currentColor"
                strokeWidth="1.2"
                opacity="0.16"
                fill="none"
            >
                {shape.links.map(([a, b]) => (
                    <line
                        key={`${a}-${b}`}
                        x1={shape.points[a][0]}
                        y1={shape.points[a][1]}
                        x2={shape.points[b][0]}
                        y2={shape.points[b][1]}
                    />
                ))}
            </g>
            {shape.points.map(([x, y], i) => (
                <rect
                    key={`${x}-${y}`}
                    x={x - size / 2}
                    y={y - size / 2}
                    width={size}
                    height={size}
                    rx={size * 0.22}
                    fill={
                        i === shape.signal ? 'var(--sl-signal)' : 'currentColor'
                    }
                    opacity={i === shape.signal ? 0.9 : 0.24}
                />
            ))}
        </svg>
    );
}
