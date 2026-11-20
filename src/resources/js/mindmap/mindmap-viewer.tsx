import {
    Panel,
    ReactFlow,
    ReactFlowProvider,
    useReactFlow,
} from '@xyflow/react';
import type { Edge } from '@xyflow/react';
import { toPng } from 'html-to-image';
import { useCallback, useMemo, useState } from 'react';
import { fileName, renderPng, save } from '@/mindmap/download';
import { MindNode } from '@/mindmap/mind-node';
import type { MindFlowNode } from '@/mindmap/mind-node';
import { branchIds, initialCollapsed, layout, toggle } from '@/mindmap/tree';
import type { MindTree } from '@/mindmap/tree';
import type { Citation, Passage } from '@/types/app';
import '@xyflow/react/dist/base.css';
import './mindmap.css';

const nodeTypes = { mind: MindNode };

const isNarrow = () =>
    typeof window !== 'undefined' &&
    window.matchMedia?.('(max-width: 640px)').matches === true;

type Props = {
    tree: MindTree;
    citations: Citation[] | null;
    onOpenCitation: (passage: Passage) => void;
    onAsk: (question: string) => void;
    /** Opens on the first level only, as on a phone. Defaults to the phone media query. */
    narrow?: boolean;
};

/**
 * The mind map as a horizontal tree (D-044): the root on the left, curved links in the mind map's tint, a round ›
 * on each branch, and a control stack to expand or collapse all, zoom, fit and download a PNG. Pan with a drag,
 * zoom with a pinch or the wheel; on a phone it opens on the first level.
 */
export function MindmapViewer(props: Props) {
    return (
        <ReactFlowProvider>
            <MindmapCanvas {...props} />
        </ReactFlowProvider>
    );
}

function MindmapCanvas({
    tree,
    citations,
    onOpenCitation,
    onAsk,
    narrow,
}: Props) {
    const [collapsed, setCollapsed] = useState(() =>
        initialCollapsed(tree, narrow ?? isNarrow()),
    );
    const [error, setError] = useState<string | null>(null);
    const flow = useReactFlow<MindFlowNode, Edge>();

    const onToggle = useCallback(
        (id: string) => setCollapsed((c) => toggle(c, id)),
        [],
    );
    const ask = useCallback(
        (label: string) =>
            onAsk(
                label === tree.title
                    ? `Give me an overview of: ${label}`
                    : `Tell me more about: ${label}`,
            ),
        [onAsk, tree.title],
    );

    const { nodes, edges } = useMemo(() => {
        const placed = layout(tree, collapsed);

        return {
            nodes: placed.nodes.map((n): MindFlowNode => ({
                id: n.id,
                type: 'mind',
                position: { x: n.x, y: n.y },
                width: n.width,
                height: n.height,
                data: {
                    ...n,
                    citations: citations ?? [],
                    onToggle,
                    onAsk: ask,
                    onOpenCitation,
                },
            })),
            edges: placed.links.map((l): Edge => ({
                id: l.id,
                source: l.source,
                target: l.target,
                type: 'default',
                className: 'mm-link',
            })),
        };
    }, [tree, collapsed, citations, onToggle, ask, onOpenCitation]);

    const allOpen = collapsed.size === 0;
    const setAll = (open: boolean) => {
        setCollapsed(open ? new Set() : new Set(branchIds(tree)));
        // let React Flow draw the new nodes, then frame them
        requestAnimationFrame(
            () => void flow.fitView({ duration: 300, padding: 0.15 }),
        );
    };

    const download = async () => {
        setError(null);
        const viewport = document.querySelector<HTMLElement>(
            '.mm .react-flow__viewport',
        );
        if (!viewport) {
            setError('The mind map is not on screen yet.');

            return;
        }
        try {
            const url = await renderPng({
                nodes: flow.getNodes(),
                viewport,
                background: getComputedStyle(viewport.closest('.mm')!)
                    .backgroundColor,
                render: toPng,
            });
            save(url, fileName(tree.title));
        } catch (e) {
            setError(e instanceof Error ? e.message : String(e));
        }
    };

    return (
        <div className="mm" data-testid="mindmap">
            <ReactFlow
                nodes={nodes}
                edges={edges}
                nodeTypes={nodeTypes}
                nodesDraggable={false}
                nodesConnectable={false}
                elementsSelectable={false}
                fitView
                fitViewOptions={{ padding: 0.15 }}
                minZoom={0.2}
                maxZoom={2}
                panOnScroll={false}
            >
                <Panel position="top-left" className="mm-controls">
                    <button
                        type="button"
                        onClick={() => setAll(!allOpen)}
                        aria-label={allOpen ? 'Collapse all' : 'Expand all'}
                        title={allOpen ? 'Collapse all' : 'Expand all'}
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            {allOpen ? (
                                <path d="M7 4l5 5 5-5 M7 20l5-5 5 5" />
                            ) : (
                                <path d="M7 9l5-5 5 5 M7 15l5 5 5-5" />
                            )}
                        </svg>
                    </button>
                    <button
                        type="button"
                        onClick={() => void flow.zoomIn({ duration: 200 })}
                        aria-label="Zoom in"
                        title="Zoom in"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M12 6v12 M6 12h12" />
                        </svg>
                    </button>
                    <button
                        type="button"
                        onClick={() => void flow.zoomOut({ duration: 200 })}
                        aria-label="Zoom out"
                        title="Zoom out"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M6 12h12" />
                        </svg>
                    </button>
                    <button
                        type="button"
                        onClick={() =>
                            void flow.fitView({ duration: 300, padding: 0.15 })
                        }
                        aria-label="Fit to screen"
                        title="Fit to screen"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M4 9V4h5 M15 4h5v5 M20 15v5h-5 M9 20H4v-5" />
                        </svg>
                    </button>
                    <button
                        type="button"
                        onClick={() => void download()}
                        aria-label="Download as PNG"
                        title="Download as PNG"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M12 4v11 M7 10l5 5 5-5 M5 20h14" />
                        </svg>
                    </button>
                </Panel>
                {error && (
                    <Panel position="bottom-center" className="mm-error">
                        <span role="alert">{error}</span>
                    </Panel>
                )}
            </ReactFlow>
        </div>
    );
}
