import { Handle, Position } from '@xyflow/react';
import type { Node, NodeProps } from '@xyflow/react';
import { CitationChip } from '@/components/notebook/citation';
import type { PlacedNode } from '@/mindmap/tree';
import type { Citation, Passage } from '@/types/app';

export type MindNodeData = PlacedNode & {
    citations: Citation[];
    onToggle: (id: string) => void;
    onAsk: (label: string) => void;
    onOpenCitation: (passage: Passage) => void;
};

export type MindFlowNode = Node<MindNodeData, 'mind'>;

/** The pill itself, without React Flow's handles, so it renders (and tests) on its own. */
export function MindPill({ data }: { data: MindNodeData }) {
    const chips = data.refs
        .map((id) => ({
            id,
            index: data.citations.findIndex((c) => c.id === id),
        }))
        .filter((c) => c.index >= 0);

    return (
        <div
            className={`mm-pill${data.depth === 0 ? ' is-root' : ''}`}
            style={{ width: data.width, minHeight: data.height }}
            data-testid="mind-node"
            data-id={data.id}
        >
            <button
                type="button"
                className="mm-label nodrag"
                title="Ask about this in the chat"
                onClick={() => data.onAsk(data.label)}
            >
                {data.label}
            </button>
            {chips.length > 0 && (
                <span className="mm-chips nodrag">
                    {chips.map(({ id, index }) => (
                        <CitationChip
                            key={id}
                            index={index}
                            citation={data.citations[index]}
                            onOpen={data.onOpenCitation}
                        />
                    ))}
                </span>
            )}
            {data.hasChildren && (
                <button
                    type="button"
                    className={`mm-toggle nodrag${data.collapsed ? '' : ' is-open'}`}
                    aria-expanded={!data.collapsed}
                    aria-label={`${data.collapsed ? 'Expand' : 'Collapse'} ${data.label}`}
                    onClick={() => data.onToggle(data.id)}
                >
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M9.5 6.5 15 12l-5.5 5.5" />
                    </svg>
                </button>
            )}
        </div>
    );
}

/** The node React Flow draws: the pill, with an invisible handle on each side for the curved links. */
export function MindNode({ data }: NodeProps<MindFlowNode>) {
    return (
        <>
            {data.depth > 0 && (
                <Handle
                    type="target"
                    position={Position.Left}
                    isConnectable={false}
                />
            )}
            <MindPill data={data} />
            {data.hasChildren && (
                <Handle
                    type="source"
                    position={Position.Right}
                    isConnectable={false}
                />
            )}
        </>
    );
}
