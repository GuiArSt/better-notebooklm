import { describe, expect, it } from 'vitest';
import {
    branchIds,
    initialCollapsed,
    layout,
    measure,
    ROOT,
    SIZE,
    toggle,
    walk,
} from '@/mindmap/tree';
import { sampleTree } from '@/mindmap/fixtures';

const ids = (collapsed: Set<string>) =>
    layout(sampleTree, collapsed).nodes.map((n) => n.id);

describe('the mind map tree', () => {
    it('gives every node a stable id from its path, with the title as the root', () => {
        const all = walk(sampleTree);

        expect(all[0]).toMatchObject({ id: ROOT, depth: 0, parent: null });
        expect(all[0].node.label).toBe('Voyager 1 and 2');
        expect(all.map((w) => w.id)).toEqual([
            'root',
            '0',
            '0.0',
            '0.1',
            '0.2',
            '0.2.0',
            '0.2.1',
            '1',
            '1.0',
            '1.1',
            '2',
        ]);
    });

    it('opens two levels deep on a desktop, and one on a phone', () => {
        expect(ids(initialCollapsed(sampleTree, false))).toEqual([
            'root',
            '0',
            '0.0',
            '0.1',
            '0.2',
            '1',
            '1.0',
            '1.1',
            '2',
        ]);
        expect([...initialCollapsed(sampleTree, true)].sort()).toEqual([
            '0',
            '0.2',
            '1',
        ]);
        expect(ids(initialCollapsed(sampleTree, true))).toEqual([
            'root',
            '0',
            '1',
            '2',
        ]);
    });

    it('expands a branch and collapses it again', () => {
        let collapsed = initialCollapsed(sampleTree, true);

        collapsed = toggle(collapsed, '0');
        expect(ids(collapsed)).toEqual([
            'root',
            '0',
            '0.0',
            '0.1',
            '0.2',
            '1',
            '2',
        ]);

        collapsed = toggle(collapsed, '0.2');
        expect(ids(collapsed)).toContain('0.2.1');

        collapsed = toggle(collapsed, '0');
        expect(ids(collapsed)).toEqual(['root', '0', '1', '2']);
        // the grandchild branch stays open inside its closed parent, so reopening the parent shows it again
        collapsed = toggle(collapsed, '0');
        expect(ids(collapsed)).toContain('0.2.1');
    });

    it('expands all and collapses all', () => {
        expect(ids(new Set())).toHaveLength(11);
        expect(ids(new Set(branchIds(sampleTree)))).toEqual([
            'root',
            '0',
            '1',
            '2',
        ]);
        expect(ids(new Set([ROOT]))).toEqual(['root']);
    });

    it('marks a node collapsed only when it has children to hide', () => {
        const { nodes } = layout(sampleTree, new Set(['0', '2']));
        const byId = Object.fromEntries(nodes.map((n) => [n.id, n]));

        expect(byId['0']).toMatchObject({ hasChildren: true, collapsed: true });
        expect(byId['2']).toMatchObject({
            hasChildren: false,
            collapsed: false,
        });
        expect(byId['1']).toMatchObject({
            hasChildren: true,
            collapsed: false,
        });
    });

    it('lays the tree out left to right: one column per depth, no two pills overlapping', () => {
        const { nodes, links } = layout(sampleTree, new Set());
        const byId = Object.fromEntries(nodes.map((n) => [n.id, n]));

        for (const link of links) {
            const a = byId[link.source];
            const b = byId[link.target];
            expect(b.x).toBeGreaterThanOrEqual(a.x + a.width + SIZE.gapX);
        }
        for (const a of nodes) {
            for (const b of nodes) {
                if (a !== b && a.x === b.x) {
                    const apart =
                        a.y + a.height <= b.y || b.y + b.height <= a.y;
                    expect(apart, `${a.id} and ${b.id} overlap`).toBe(true);
                }
            }
        }
        // a parent sits level with the middle of its children
        const kids = nodes.filter((n) => n.parent === '0');
        const mid =
            (kids[0].y +
                kids[0].height / 2 +
                kids.at(-1)!.y +
                kids.at(-1)!.height / 2) /
            2;
        expect(byId['0'].y + byId['0'].height / 2).toBeCloseTo(mid);
        expect(links).toHaveLength(nodes.length - 1);
    });

    it('keeps the citation ids on each node, and none on the root', () => {
        const { nodes } = layout(sampleTree, new Set());
        const byId = Object.fromEntries(nodes.map((n) => [n.id, n]));

        expect(byId.root.refs).toEqual([]);
        expect(byId['0.1'].refs).toEqual([2]);
    });

    it('wraps a long label inside the maximum width instead of growing forever', () => {
        const short = measure('Saturn', 1);
        const long = measure('x'.repeat(120), 2);

        expect(short.height).toBe(SIZE.lineHeight + SIZE.padY * 2);
        expect(long.width).toBe(SIZE.maxWidth);
        expect(long.height).toBeGreaterThan(3 * SIZE.lineHeight);
    });

    it('survives an empty map', () => {
        const { nodes, links } = layout(
            { title: 'Empty', children: [] },
            new Set(),
        );

        expect(nodes).toHaveLength(1);
        expect(links).toEqual([]);
    });
});
