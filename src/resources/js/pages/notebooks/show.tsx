import { Head, Link, usePoll } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useEffect, useState } from 'react';
import { NotebookIcon } from '@/components/notebook-icon';
import { ChatPanel } from '@/components/notebook/chat-panel';
import { SourcesPanel } from '@/components/notebook/sources-panel';
import { StudioPanel } from '@/components/notebook/studio-panel';
import { UsageMeter } from '@/components/usage-meter';
import { Button } from '@/components/ui/button';
import type { Infographic, Podcast } from '@/lib/customize';
import { cn } from '@/lib/utils';
import type { Note, NotebookSummary, Passage, Source } from '@/types/app';

type Props = {
    notebook: NotebookSummary;
    sources: Source[];
    notes: Note[];
    maxSourceChars: number;
    podcast: Podcast;
    infographic: Infographic;
};

type Tab = 'sources' | 'chat' | 'studio';

const TABS: { id: Tab; label: string }[] = [
    { id: 'sources', label: 'Sources' },
    { id: 'chat', label: 'Chat' },
    { id: 'studio', label: 'Studio' },
];

export default function NotebookShow({
    notebook,
    sources,
    notes,
    maxSourceChars,
    podcast,
    infographic,
}: Props) {
    const [tab, setTab] = useState<Tab>('chat');
    const [collapsed, setCollapsed] = useState({
        sources: false,
        studio: false,
    });
    const [viewing, setViewing] = useState<number | null>(null);
    const [passage, setPassage] = useState<Passage | null>(null);
    const [request, setRequest] = useState<{ id: number; text: string } | null>(
        null,
    );

    // Poll while a source is still being read and embedded.
    const pending = sources.some((s) => s.status === 'pending');

    const { start, stop } = usePoll(
        1500,
        { only: ['sources', 'usage'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (pending) {
            start();
        } else {
            stop();
        }
    }, [pending, start, stop]);

    const openCitation = (p: Passage) => {
        setPassage(p);
        setViewing(p.sourceId);
        setTab('sources');
        setCollapsed((c) => ({ ...c, sources: false }));
    };

    const askInChat = (text: string) => {
        setTab('chat');
        setRequest((r) => ({ id: (r?.id ?? 0) + 1, text }));
    };

    const closeViewer = () => {
        setViewing(null);
        setPassage(null);
    };

    // Sources and Studio are white panels on the canvas; the chat sits on the canvas itself.
    const panel = (id: Tab) =>
        cn(
            'min-h-0 flex-col overflow-hidden',
            id !== 'chat' && 'rounded-panel bg-panel',
            tab === id ? 'flex' : 'hidden lg:flex',
        );

    const sourcesWidth = collapsed.sources
        ? '3.25rem'
        : viewing !== null
          ? '30rem'
          : '20rem';
    // While a source is open the Studio steps aside, so the chat keeps a readable width.
    const studioCollapsed = collapsed.studio || viewing !== null;
    const studioWidth = studioCollapsed ? '3.25rem' : '20rem';

    return (
        <>
            <Head title={notebook.title} />
            <div className="bg-constellation-dense flex h-full flex-col gap-3 p-2 sm:p-3">
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1 px-1">
                    <Button
                        asChild
                        variant="ghost"
                        size="sm"
                        className="gap-1 px-2"
                    >
                        <Link
                            href="/notebooks"
                            aria-label="Back to all notebooks"
                        >
                            <ChevronLeft />
                            <span className="hidden sm:inline">
                                All notebooks
                            </span>
                        </Link>
                    </Button>
                    <NotebookIcon
                        id={notebook.id}
                        emoji={notebook.emoji}
                        status={notebook.icon_status}
                        size="sm"
                    />
                    <h1
                        className="line-clamp-2 min-w-0 flex-1 text-title font-medium break-words sm:truncate"
                        data-testid="notebook-title"
                    >
                        {notebook.title}
                    </h1>
                    {/* On a phone the budget gets its own row, so the title keeps the full width. */}
                    <UsageMeter className="order-last w-full sm:hidden" />
                </div>

                {/* Phones and tablets: one panel at a time. */}
                <div
                    className="grid grid-cols-3 rounded-full bg-panel p-1 text-sm lg:hidden"
                    role="tablist"
                >
                    {TABS.map((t) => (
                        <button
                            key={t.id}
                            type="button"
                            role="tab"
                            aria-selected={tab === t.id}
                            onClick={() => setTab(t.id)}
                            className={cn(
                                'rounded-full py-1.5 font-medium text-muted-foreground',
                                tab === t.id && 'bg-bubble text-foreground',
                            )}
                        >
                            {t.label}
                        </button>
                    ))}
                </div>

                <div
                    className="grid min-h-0 flex-1 gap-3 lg:[grid-template-columns:var(--sources)_minmax(0,1fr)_var(--studio)]"
                    style={
                        {
                            '--sources': sourcesWidth,
                            '--studio': studioWidth,
                        } as React.CSSProperties
                    }
                >
                    <section
                        className={panel('sources')}
                        aria-label="Sources"
                        data-testid="panel-sources"
                    >
                        <PanelHeader
                            title="Sources"
                            collapsed={collapsed.sources}
                            side="left"
                            onToggle={() =>
                                setCollapsed((c) => ({
                                    ...c,
                                    sources: !c.sources,
                                }))
                            }
                        />
                        {!collapsed.sources && (
                            <SourcesPanel
                                notebookId={notebook.id}
                                sources={sources}
                                notes={notes}
                                maxChars={maxSourceChars}
                                viewing={viewing}
                                passage={passage}
                                onView={(id) => {
                                    setPassage(null);
                                    setViewing(id);
                                }}
                                onCloseViewer={closeViewer}
                                onOpenCitation={openCitation}
                            />
                        )}
                    </section>

                    <section
                        className={panel('chat')}
                        aria-label="Chat"
                        data-testid="panel-chat"
                    >
                        <ChatPanel
                            notebook={notebook}
                            notebookId={notebook.id}
                            sources={sources}
                            onOpenCitation={openCitation}
                            request={request}
                        />
                    </section>

                    <section
                        className={panel('studio')}
                        aria-label="Studio"
                        data-testid="panel-studio"
                    >
                        <PanelHeader
                            title="Studio"
                            collapsed={studioCollapsed}
                            side="right"
                            onToggle={() =>
                                setCollapsed((c) => ({
                                    ...c,
                                    studio: !c.studio,
                                }))
                            }
                        />
                        {!studioCollapsed && (
                            <StudioPanel
                                notebookId={notebook.id}
                                sources={sources}
                                podcast={podcast}
                                infographic={infographic}
                                onOpenCitation={openCitation}
                                onAsk={askInChat}
                            />
                        )}
                    </section>
                </div>
            </div>
        </>
    );
}

/** A panel's title bar; on a wide screen its button collapses the panel to a thin rail. */
function PanelHeader({
    title,
    collapsed,
    side,
    onToggle,
}: {
    title: string;
    collapsed: boolean;
    side: 'left' | 'right';
    onToggle: () => void;
}) {
    const Icon = (side === 'left') !== collapsed ? ChevronLeft : ChevronRight;

    return (
        <div
            className={cn(
                'flex items-center justify-between px-4 pt-3 pb-1',
                collapsed && 'lg:justify-center lg:px-0',
            )}
        >
            <h2
                className={cn(
                    'text-body font-medium',
                    collapsed && 'lg:hidden',
                )}
            >
                {title}
            </h2>
            <Button
                variant="ghost"
                size="icon"
                className="hidden size-7 lg:inline-flex"
                onClick={onToggle}
                aria-label={collapsed ? `Show ${title}` : `Hide ${title}`}
            >
                <Icon />
            </Button>
        </div>
    );
}
