/**
 * Download the whole mind map as a PNG, React Flow's documented way: frame every node's bounds, then let
 * html-to-image draw the viewport element at that frame (at most 4096 px a side), at twice the pixels for a sharp image.
 */
import { getNodesBounds } from '@xyflow/react';
import type { Node } from '@xyflow/react';

type Render = (
    element: HTMLElement,
    options: {
        backgroundColor: string;
        width: number;
        height: number;
        pixelRatio: number;
        style: Record<string, string>;
    },
) => Promise<string>;

const PADDING = 48;
const MAX_SIDE = 4096;

/** A file name a desktop accepts: the title in lower case, words joined by hyphens. */
export function fileName(title: string): string {
    const base = title
        .toLowerCase()
        .normalize('NFKD')
        .replace(/\p{M}/gu, '')
        .replace(/[^\p{L}\p{N}]+/gu, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 60);

    return `${base || 'mind-map'}.png`;
}

/** Renders the map to a PNG data URL. Throws if the renderer gives back anything but a PNG, so it never fails silently. */
export async function renderPng({
    nodes,
    viewport,
    background,
    render,
    prepare = inlineStrokes,
}: {
    nodes: Node[];
    viewport: HTMLElement;
    background: string;
    render: Render;
    /** Readies the DOM for the capture and returns how to undo it. */
    prepare?: (viewport: HTMLElement) => () => void;
}): Promise<string> {
    const bounds = getNodesBounds(nodes);
    const full = Math.max(bounds.width, bounds.height) + PADDING * 2;
    const zoom = Math.min(1, MAX_SIDE / full);
    const width = Math.ceil((bounds.width + PADDING * 2) * zoom);
    const height = Math.ceil((bounds.height + PADDING * 2) * zoom);
    const x = (PADDING - bounds.x) * zoom;
    const y = (PADDING - bounds.y) * zoom;

    const restore = prepare(viewport);
    let url: string;
    try {
        url = await render(viewport, {
            backgroundColor: background,
            width,
            height,
            pixelRatio: 2,
            style: {
                width: `${width}px`,
                height: `${height}px`,
                transform: `translate(${x}px, ${y}px) scale(${zoom})`,
            },
        });
    } finally {
        restore();
    }
    if (!url.startsWith('data:image/png')) {
        throw new Error('The mind map could not be drawn as a PNG.');
    }

    return url;
}

/**
 * html-to-image copies no stylesheet into its clone, and an SVG path keeps no computed style there, so the links
 * would vanish from the PNG. For the capture, each link gets its resolved stroke inline; afterwards its own style returns.
 */
export function inlineStrokes(viewport: HTMLElement): () => void {
    const paths = [
        ...viewport.querySelectorAll<SVGPathElement>('.react-flow__edge-path'),
    ];
    const before = paths.map((p) => p.getAttribute('style'));
    for (const p of paths) {
        const computed = getComputedStyle(p);
        p.style.stroke = computed.stroke;
        p.style.strokeWidth = computed.strokeWidth;
        p.style.strokeOpacity = computed.strokeOpacity;
        p.style.fill = 'none';
    }

    return () =>
        paths.forEach((p, i) =>
            before[i] === null
                ? p.removeAttribute('style')
                : p.setAttribute('style', before[i]),
        );
}

/** Hands a data URL to the browser as a download. */
export function save(url: string, name: string): void {
    const link = document.createElement('a');
    link.href = url;
    link.download = name;
    link.click();
}
