import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';
import { fileName, renderPng } from '@/mindmap/download';
import { sampleCitations, sampleTree } from '@/mindmap/fixtures';
import { MindPill } from '@/mindmap/mind-node';
import type { MindNodeData } from '@/mindmap/mind-node';
import { layout } from '@/mindmap/tree';

const placed = Object.fromEntries(
    layout(sampleTree, new Set()).nodes.map((n) => [n.id, n]),
);

const data = (id: string, extra: Partial<MindNodeData> = {}): MindNodeData => ({
    ...placed[id],
    citations: sampleCitations,
    onToggle: () => undefined,
    onAsk: () => undefined,
    onOpenCitation: () => undefined,
    ...extra,
});

const pill = (d: MindNodeData) => renderToStaticMarkup(<MindPill data={d} />);

describe('a mind map node', () => {
    it('draws one citation chip per cited passage, numbered as in the item', () => {
        const out = pill(data('0.2'));

        expect(out.match(/data-testid="citation"/g)).toHaveLength(1);
        expect(out).toContain('>3</button>'); // passage 3 is the item's third citation
    });

    it('draws no chip for a passage the item does not carry, and none on the root', () => {
        expect(pill(data('0.1', { refs: [99] }))).not.toContain(
            'data-testid="citation"',
        );
        expect(pill(data('root'))).not.toContain('data-testid="citation"');
        expect(pill(data('root'))).toContain('is-root');
    });

    it('shows a round › on a branch, saying whether it will expand or collapse', () => {
        const open = pill(data('0', { collapsed: false }));
        const closed = pill(data('0', { collapsed: true }));

        expect(open).toContain('aria-expanded="true"');
        expect(open).toContain('aria-label="Collapse The Grand Tour"');
        expect(closed).toContain('aria-expanded="false"');
        expect(closed).toContain('aria-label="Expand The Grand Tour"');
    });

    it('shows no › on a leaf', () => {
        expect(pill(data('0.0'))).not.toContain('mm-toggle');
    });

    it('a click on the › and on the label reach the viewer', () => {
        const onToggle = vi.fn();
        const onAsk = vi.fn();
        const d = data('1', { onToggle, onAsk });

        d.onToggle(d.id);
        d.onAsk(d.label);
        expect(onToggle).toHaveBeenCalledWith('1');
        expect(onAsk).toHaveBeenCalledWith('Interstellar space');
    });
});

describe('the PNG download', () => {
    const nodes = layout(sampleTree, new Set()).nodes.map((n) => ({
        id: n.id,
        position: { x: n.x, y: n.y },
        width: n.width,
        height: n.height,
        data: {},
    }));
    const viewport = {} as HTMLElement;

    it('frames every node, with padding, at twice the pixels, and returns a PNG', async () => {
        const render = vi.fn(async () => 'data:image/png;base64,iVBORw0KGgo=');
        const restore = vi.fn();
        const prepare = vi.fn(() => restore);

        const url = await renderPng({
            nodes,
            viewport,
            background: 'rgb(255, 255, 255)',
            render,
            prepare,
        });

        expect(url.startsWith('data:image/png')).toBe(true);
        // the links get their strokes inline for the capture, and lose them again after
        expect(prepare).toHaveBeenCalledWith(viewport);
        expect(restore).toHaveBeenCalledOnce();
        const [element, options] = render.mock.calls[0] as unknown as [
            HTMLElement,
            {
                width: number;
                height: number;
                pixelRatio: number;
                style: { transform: string };
            },
        ];
        const right = Math.max(...nodes.map((n) => n.position.x + n.width));
        const bottom = Math.max(...nodes.map((n) => n.position.y + n.height));
        expect(element).toBe(viewport);
        expect(options.width).toBe(Math.ceil(right + 96));
        expect(options.height).toBe(Math.ceil(bottom + 96));
        expect(options.pixelRatio).toBe(2);
        expect(options.style.transform).toBe('translate(48px, 48px) scale(1)');
    });

    it('fails loudly when the renderer gives back anything but a PNG, and still restores the DOM', async () => {
        const render = async () => 'data:,';
        const restore = vi.fn();

        await expect(
            renderPng({
                nodes,
                viewport,
                background: '#fff',
                render,
                prepare: () => restore,
            }),
        ).rejects.toThrow('could not be drawn as a PNG');
        expect(restore).toHaveBeenCalledOnce();

        const failing = async () => {
            throw new Error('canvas tainted');
        };
        const restore2 = vi.fn();
        await expect(
            renderPng({
                nodes,
                viewport,
                background: '#fff',
                render: failing,
                prepare: () => restore2,
            }),
        ).rejects.toThrow('canvas tainted');
        expect(restore2).toHaveBeenCalledOnce();
    });

    it('names the file after the map', () => {
        expect(fileName('Voyager 1 and 2')).toBe('voyager-1-and-2.png');
        expect(fileName('Ünïcode: “quotes” & more!')).toBe(
            'unicode-quotes-more.png',
        );
        expect(fileName('!!!')).toBe('mind-map.png');
    });
});
