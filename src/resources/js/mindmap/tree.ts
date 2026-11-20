/**
 * The mind map's pure logic: the model's tree (unchanged, see StudioGenerator::parseMindmap) becomes positioned
 * nodes and links for a horizontal tree, with the root on the left. No drawing here, so all of it is testable.
 */
import type { MindNode } from '@/types/studio';

export type MindTree = { title: string; children: MindNode[] };

/** One node as drawn: a stable id from its path ("root", "0", "0.2"), where it sits, and what it shows. */
export type PlacedNode = {
    id: string;
    parent: string | null;
    depth: number;
    label: string;
    refs: number[];
    hasChildren: boolean;
    collapsed: boolean;
    x: number;
    y: number;
    width: number;
    height: number;
};

export type Link = { id: string; source: string; target: string };

export const ROOT = 'root';

/** Sizes in px. A label wraps inside MAX_WIDTH; the height follows from an estimate of its lines. */
export const SIZE = {
    charWidth: 7.4,
    lineHeight: 20,
    padX: 32,
    padY: 14,
    chipWidth: 22,
    minWidth: 96,
    maxWidth: 260,
    gapX: 72,
    gapY: 14,
} as const;

type Walked = {
    id: string;
    node: MindNode;
    depth: number;
    parent: string | null;
};

function rootNode(tree: MindTree): MindNode {
    return { label: tree.title, refs: [], children: tree.children ?? [] };
}

/** Every node of the tree, depth first, with its id. */
export function walk(tree: MindTree): Walked[] {
    const out: Walked[] = [];
    const visit = (
        node: MindNode,
        id: string,
        depth: number,
        parent: string | null,
    ) => {
        out.push({ id, node, depth, parent });
        (node.children ?? []).forEach((child, i) =>
            visit(child, id === ROOT ? `${i}` : `${id}.${i}`, depth + 1, id),
        );
    };
    visit(rootNode(tree), ROOT, 0, null);

    return out;
}

/** The ids of every node that has children: "collapse all" collapses these, except the root. */
export function branchIds(tree: MindTree): string[] {
    return walk(tree)
        .filter((w) => w.id !== ROOT && (w.node.children ?? []).length > 0)
        .map((w) => w.id);
}

/** The opening state: two levels below the root on a desktop, one on a phone; everything deeper closed. */
export function initialCollapsed(tree: MindTree, narrow: boolean): Set<string> {
    const depthOpen = narrow ? 1 : 2;

    return new Set(
        walk(tree)
            .filter(
                (w) =>
                    w.id !== ROOT &&
                    w.depth >= depthOpen &&
                    (w.node.children ?? []).length > 0,
            )
            .map((w) => w.id),
    );
}

/** Opens a closed branch, closes an open one. Returns a new set. */
export function toggle(collapsed: Set<string>, id: string): Set<string> {
    const next = new Set(collapsed);
    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }

    return next;
}

/** How big a label's pill is: width from its length, capped, and as many lines as it then needs. */
export function measure(
    label: string,
    chips: number,
): { width: number; height: number } {
    const text = label.length * SIZE.charWidth;
    const extra = SIZE.padX + chips * SIZE.chipWidth + 28; // 28: the round › button
    const width = Math.min(
        SIZE.maxWidth,
        Math.max(SIZE.minWidth, text + extra),
    );
    const lines = Math.max(1, Math.ceil(text / (width - extra)));

    return { width, height: lines * SIZE.lineHeight + SIZE.padY * 2 };
}

/**
 * Lays out the visible nodes: each depth is a column (as wide as its widest pill), leaves stack downwards,
 * and a parent sits level with the middle of its children. Collapsed branches hide their descendants.
 */
export function layout(
    tree: MindTree,
    collapsed: Set<string>,
): { nodes: PlacedNode[]; links: Link[] } {
    const walked = walk(tree);
    const hidden = new Set<string>();
    for (const w of walked) {
        if (
            w.parent !== null &&
            (hidden.has(w.parent) || collapsed.has(w.parent))
        ) {
            hidden.add(w.id);
        }
    }
    const visible = walked.filter((w) => !hidden.has(w.id));

    const sized = new Map(
        visible.map((w) => [
            w.id,
            measure(
                w.node.label,
                w.id === ROOT ? 0 : (w.node.refs ?? []).length,
            ),
        ]),
    );
    const columns: number[] = [];
    for (const w of visible) {
        columns[w.depth] = Math.max(
            columns[w.depth] ?? 0,
            sized.get(w.id)!.width,
        );
    }
    const columnX = columns.map((_, d) =>
        columns.slice(0, d).reduce((sum, c) => sum + c + SIZE.gapX, 0),
    );

    const kids = new Map<string, string[]>();
    for (const w of visible) {
        if (w.parent !== null) {
            kids.set(w.parent, [...(kids.get(w.parent) ?? []), w.id]);
        }
    }

    const centreY = new Map<string, number>();
    let cursor = 0;
    const place = (id: string): number => {
        const children = kids.get(id) ?? [];
        const { height } = sized.get(id)!;
        if (children.length === 0) {
            const y = cursor + height / 2;
            cursor += height + SIZE.gapY;
            centreY.set(id, y);

            return y;
        }
        const ys = children.map(place);
        const y = (ys[0] + ys[ys.length - 1]) / 2;
        centreY.set(id, y);

        return y;
    };
    place(ROOT);

    const nodes: PlacedNode[] = visible.map((w) => {
        const { width, height } = sized.get(w.id)!;
        const hasChildren = (w.node.children ?? []).length > 0;

        return {
            id: w.id,
            parent: w.parent,
            depth: w.depth,
            label: w.node.label,
            refs: w.id === ROOT ? [] : (w.node.refs ?? []),
            hasChildren,
            collapsed: hasChildren && collapsed.has(w.id),
            x: columnX[w.depth],
            y: centreY.get(w.id)! - height / 2,
            width,
            height,
        };
    });
    const links: Link[] = visible
        .filter((w) => w.parent !== null)
        .map((w) => ({
            id: `${w.parent}->${w.id}`,
            source: w.parent!,
            target: w.id,
        }));

    return { nodes, links };
}
