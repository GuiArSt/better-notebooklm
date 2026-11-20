import { Globe, WandSparkles } from 'lucide-react';
import type { ComponentType, SVGAttributes } from 'react';
import {
    AudioIcon,
    FlashcardsIcon,
    InfographicIcon,
    MindmapIcon,
    QuizIcon,
    ReportsIcon,
    SlidesIcon,
    TableIcon,
    VideoIcon,
} from '@/components/studio-icons';
import { useCallback, useEffect, useState } from 'react';
import { AudioPlayer } from '@/components/notebook/audio-player';
import { ModelBadges } from '@/components/notebook/model-badges';
import { InfographicViewer } from '@/components/notebook/infographic-viewer';
import { HowMadeDialog } from '@/components/notebook/how-made-dialog';
import { CustomizeDialog } from '@/components/notebook/customize-dialog';
import { KindBadge } from '@/components/notebook/kind-badge';
import { StudioItemRow } from '@/components/notebook/studio-item-row';
import { SlideViewer } from '@/components/notebook/slide-viewer';
import { QuizViewer } from '@/components/notebook/quiz-viewer';
import { ReportViewer } from '@/components/notebook/report-viewer';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { CustomizeRequest, Infographic, Podcast } from '@/lib/customize';
import { TILE_OF_KIND, TILE_TINTS } from '@/lib/studio-tints';
import type { TileKey } from '@/lib/studio-tints';
import { HttpError, getJson, sendJson } from '@/lib/http';
import { MindmapViewer } from '@/mindmap/mindmap-viewer';
import { readingHint } from '@/lib/source-readiness';
import type { Passage, Source } from '@/types/app';
import type { StudioItem, StudioKind } from '@/types/studio';

const GREYED: {
    label: string;
    icon: ComponentType<SVGAttributes<SVGElement>>;
    tile?: TileKey;
}[] = [
    { label: 'Video Overview', icon: VideoIcon, tile: 'video' },
    { label: 'Flashcards', icon: FlashcardsIcon, tile: 'flashcards' },
    { label: 'Infographic', icon: InfographicIcon, tile: 'infographic' },
    { label: 'Data Table', icon: TableIcon, tile: 'table' },
    { label: 'Site', icon: Globe },
];

const REPORTS: { kind: StudioKind; label: string }[] = [
    { kind: 'briefing', label: 'Briefing document' },
    { kind: 'study_guide', label: 'Study guide' },
    { kind: 'faq', label: 'FAQ' },
];

const TILE =
    'flex min-h-14 items-center gap-3 rounded-2xl border border-tile-border bg-panel px-3 py-2 text-left text-body font-medium transition hover:bg-card-soft disabled:cursor-not-allowed disabled:opacity-50';

/** How many different sources an item's citations point to. */
const citedSources = (item: StudioItem) =>
    new Set((item.citations ?? []).map((c) => c.source_id)).size;

export function StudioPanel({
    notebookId,
    sources,
    podcast,
    infographic,
    onOpenCitation,
    onAsk,
}: {
    notebookId: number;
    sources: Source[];
    podcast: Podcast;
    infographic: Infographic;
    onOpenCitation: (passage: Passage) => void;
    onAsk: (question: string) => void;
}) {
    const [items, setItems] = useState<StudioItem[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [listFailed, setListFailed] = useState(false);
    const [opened, setOpened] = useState<StudioItem | null>(null);
    const [customizing, setCustomizing] = useState<StudioKind | null>(null);
    const [howOpened, setHowOpened] = useState<StudioItem | null>(null);

    const ready = sources.some((s) => s.checked && s.status === 'ready');
    const needSource = readingHint(sources) ?? 'Check a ready source first';
    const busy = items.some(
        (i) => i.status === 'pending' || i.status === 'running',
    );

    const shownError =
        error ?? (listFailed ? 'The Studio list could not be loaded.' : null);

    /** What went wrong, in the server's own words when it gave any. */
    const wording = (e: unknown, fallback: string): string[] =>
        e instanceof HttpError ? e.messages() : [fallback];

    const load = useCallback(async () => {
        try {
            const data = await getJson<{ items: StudioItem[] }>(
                `/notebooks/${notebookId}/studio`,
            );
            setItems(data.items);
            setListFailed(false);
        } catch {
            setListFailed(true);
        }
    }, [notebookId]);

    useEffect(() => {
        void load();
    }, [load]);

    // While something is being made, look again every two seconds.
    useEffect(() => {
        if (!busy) {
            return;
        }

        const timer = setInterval(() => void load(), 2000);

        return () => clearInterval(timer);
    }, [busy, load]);

    /** Start an item. Returns the server's messages when it refuses (the Customize dialog shows them), else null. */
    const create = async (
        request: CustomizeRequest,
    ): Promise<string[] | null> => {
        setError(null);

        try {
            await sendJson(`/notebooks/${notebookId}/studio`, 'POST', request);
            void load();

            return null;
        } catch (e) {
            void load();

            return wording(e, 'That could not be started.');
        }
    };

    // Make it again: a new item with the same recorded settings; the old one stays.
    const regenerate = async (item: StudioItem) => {
        setError(null);

        try {
            await sendJson(
                `/notebooks/${notebookId}/studio/${item.id}/regenerate`,
                'POST',
            );
        } catch (e) {
            setError(wording(e, 'That could not be started again.')[0]);
        }

        void load();
    };

    const rename = async (item: StudioItem, title: string) => {
        setItems((all) =>
            all.map((i) => (i.id === item.id ? { ...i, title } : i)),
        );

        try {
            await sendJson(
                `/notebooks/${notebookId}/studio/${item.id}`,
                'PATCH',
                { title },
            );
        } catch (e) {
            setError(wording(e, 'The title could not be saved.')[0]);
        }

        void load();
    };

    const remove = async (item: StudioItem) => {
        try {
            await sendJson(
                `/notebooks/${notebookId}/studio/${item.id}`,
                'DELETE',
            );
        } catch (e) {
            setError(wording(e, 'That could not be deleted.')[0]);
        }

        void load();
    };

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <div className="flex-1 space-y-4 overflow-y-auto p-3">
                <div
                    className="grid grid-cols-2 gap-2"
                    data-testid="studio-tiles"
                >
                    <button
                        type="button"
                        disabled={!ready}
                        onClick={() => setCustomizing('audio')}
                        title={ready ? undefined : needSource}
                        className={TILE}
                        data-testid="tile-audio"
                    >
                        <AudioIcon
                            className={`size-5 shrink-0 ${TILE_TINTS.audio.text}`}
                        />
                        Audio Overview
                    </button>
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <button
                                type="button"
                                disabled={!ready}
                                title={ready ? undefined : needSource}
                                className={TILE}
                                data-testid="tile-report"
                            >
                                <ReportsIcon
                                    className={`size-5 shrink-0 ${TILE_TINTS.reports.text}`}
                                />
                                Reports
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            {REPORTS.map((r) => (
                                <DropdownMenuItem
                                    key={r.kind}
                                    onSelect={() => setCustomizing(r.kind)}
                                >
                                    {r.label}
                                </DropdownMenuItem>
                            ))}
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <button
                        type="button"
                        disabled={!ready}
                        onClick={() => setCustomizing('mindmap')}
                        title={ready ? undefined : needSource}
                        className={TILE}
                        data-testid="tile-mindmap"
                    >
                        <MindmapIcon
                            className={`size-5 shrink-0 ${TILE_TINTS.mindmap.text}`}
                        />
                        Mind Map
                    </button>
                    <button
                        type="button"
                        disabled={!ready}
                        onClick={() => setCustomizing('quiz')}
                        title={ready ? undefined : needSource}
                        className={TILE}
                        data-testid="tile-quiz"
                    >
                        <QuizIcon
                            className={`size-5 shrink-0 ${TILE_TINTS.quiz.text}`}
                        />
                        Quiz
                    </button>
                    <button
                        type="button"
                        disabled={!ready}
                        onClick={() => setCustomizing('slides')}
                        title={ready ? undefined : needSource}
                        className={TILE}
                        data-testid="tile-slides"
                    >
                        <SlidesIcon
                            className={`size-5 shrink-0 ${TILE_TINTS.slides.text}`}
                        />
                        Slide Deck
                    </button>
                    {infographic.available && (
                        <button
                            type="button"
                            disabled={!ready}
                            onClick={() => setCustomizing('infographic')}
                            title={ready ? undefined : needSource}
                            className={TILE}
                            data-testid="tile-infographic"
                        >
                            <InfographicIcon
                                className={`size-5 shrink-0 ${TILE_TINTS.infographic.text}`}
                            />
                            Infographic
                        </button>
                    )}
                    {GREYED.filter(
                        (g) =>
                            !(
                                g.tile === 'infographic' &&
                                infographic.available
                            ),
                    ).map(({ label, icon: Icon, tile }) => (
                        <div
                            key={label}
                            aria-disabled="true"
                            className={`${TILE} cursor-not-allowed flex-col items-start justify-center gap-0 opacity-50 hover:bg-panel`}
                        >
                            <span className="flex items-center gap-2">
                                <Icon
                                    className={`size-5 shrink-0 ${tile ? TILE_TINTS[tile].text : 'text-muted-foreground'}`}
                                />{' '}
                                {label}
                            </span>
                            <span className="text-[10px]">
                                not in this demo
                            </span>
                        </div>
                    ))}
                </div>

                {shownError && (
                    <p className="text-sm text-destructive" role="alert">
                        {shownError}
                    </p>
                )}

                <section>
                    {items.length === 0 && !listFailed ? (
                        <div
                            className="flex flex-col items-center gap-1 px-2 pt-10 text-center text-body text-muted-foreground"
                            data-testid="studio-empty"
                        >
                            <WandSparkles className="mb-1 size-6" />
                            <p className="font-medium text-foreground">
                                Studio output will be saved here.
                            </p>
                            <p>
                                After adding sources, make an audio overview, a
                                report or a mind map.
                            </p>
                        </div>
                    ) : (
                        <>
                            <h3 className="mb-2 text-body font-medium">
                                Generated
                            </h3>
                            <ul
                                className="flex flex-col gap-2"
                                data-testid="studio-items"
                            >
                                {items.map((item) => (
                                    <StudioItemRow
                                        key={item.id}
                                        item={item}
                                        onOpen={() => setOpened(item)}
                                        onDelete={() => void remove(item)}
                                        onRetry={() => void regenerate(item)}
                                        onRegenerate={() =>
                                            void regenerate(item)
                                        }
                                        onRename={(title) =>
                                            void rename(item, title)
                                        }
                                    />
                                ))}
                            </ul>
                        </>
                    )}
                </section>
            </div>

            <Dialog
                open={opened !== null}
                onOpenChange={(o) => !o && setOpened(null)}
            >
                <DialogContent
                    className={`max-h-[92vh] gap-3 overflow-y-auto rounded-panel border-0 bg-panel p-6 ${opened?.kind === 'slides' || opened?.kind === 'infographic' ? 'max-w-5xl sm:max-w-5xl' : 'max-w-2xl'}`}
                >
                    <DialogHeader>
                        <div className="flex items-center gap-3">
                            {opened && (
                                <KindBadge
                                    tile={
                                        TILE_OF_KIND[opened.kind] ?? 'reports'
                                    }
                                    size="lg"
                                />
                            )}
                            <div className="min-w-0">
                                <p
                                    className="text-caption text-muted-foreground"
                                    data-testid="item-breadcrumb"
                                >
                                    Studio ›{' '}
                                    {opened?.kind === 'audio'
                                        ? 'Audio Overview'
                                        : opened?.kind === 'mindmap'
                                          ? 'Mind Map'
                                          : opened?.kind === 'quiz'
                                            ? 'Quiz'
                                            : opened?.kind === 'slides'
                                              ? 'Slide Deck'
                                              : opened?.kind === 'infographic'
                                                ? 'Infographic'
                                                : 'Reports'}
                                </p>
                                <DialogTitle className="text-title font-normal">
                                    {opened?.title}
                                </DialogTitle>
                                {opened && (
                                    <ModelBadges
                                        item={opened}
                                        className="mt-1"
                                    />
                                )}
                            </div>
                        </div>
                        <DialogDescription className="sr-only">
                            {opened?.kind === 'audio'
                                ? 'Two hosts talk through your sources.'
                                : opened?.kind === 'mindmap'
                                  ? 'Click a topic to ask about it in the chat.'
                                  : opened?.kind === 'infographic'
                                    ? 'A picture drawn from a short cited brief of your sources.'
                                    : 'Written from your checked sources. Hover a number to read the quote.'}
                        </DialogDescription>
                        {opened && (
                            <button
                                type="button"
                                data-testid="view-how"
                                className="w-fit text-caption text-muted-foreground underline"
                                onClick={() => setHowOpened(opened)}
                            >
                                How this was made
                            </button>
                        )}
                        {opened && citedSources(opened) > 0 && (
                            <button
                                type="button"
                                data-testid="view-sources"
                                className="w-fit rounded-full bg-card-soft px-4 py-1.5 text-body hover:bg-bubble"
                                onClick={() => {
                                    const first = opened.citations?.[0];

                                    setOpened(null);

                                    if (first) {
                                        onOpenCitation({
                                            sourceId: first.source_id,
                                            start: first.start,
                                            end: first.end,
                                            page: first.page,
                                        });
                                    }
                                }}
                            >
                                View {citedSources(opened)}{' '}
                                {citedSources(opened) === 1
                                    ? 'source'
                                    : 'sources'}
                            </button>
                        )}
                    </DialogHeader>
                    {opened?.kind === 'audio' && <AudioPlayer item={opened} />}
                    {opened?.kind === 'slides' && (
                        <SlideViewer
                            item={opened}
                            onOpenCitation={(p) => {
                                setOpened(null);
                                onOpenCitation(p);
                            }}
                        />
                    )}
                    {opened?.kind === 'quiz' && (
                        <QuizViewer
                            item={opened}
                            onOpenCitation={(p) => {
                                setOpened(null);
                                onOpenCitation(p);
                            }}
                        />
                    )}
                    {opened?.kind === 'infographic' && (
                        <InfographicViewer
                            item={opened}
                            onOpenCitation={(p) => {
                                setOpened(null);
                                onOpenCitation(p);
                            }}
                        />
                    )}
                    {opened?.kind === 'mindmap' && opened.tree && (
                        <MindmapViewer
                            tree={opened.tree}
                            citations={opened.citations}
                            onOpenCitation={(p) => {
                                setOpened(null);
                                onOpenCitation(p);
                            }}
                            onAsk={(q) => {
                                setOpened(null);
                                onAsk(q);
                            }}
                        />
                    )}
                    {opened &&
                        opened.kind !== 'audio' &&
                        opened.kind !== 'mindmap' &&
                        opened.kind !== 'quiz' &&
                        opened.kind !== 'slides' &&
                        opened.kind !== 'infographic' && (
                            <ReportViewer
                                item={opened}
                                onOpenCitation={(p) => {
                                    setOpened(null);
                                    onOpenCitation(p);
                                }}
                            />
                        )}
                </DialogContent>
            </Dialog>
            {customizing && (
                <CustomizeDialog
                    kind={customizing}
                    sources={sources}
                    onClose={() => setCustomizing(null)}
                    notebookId={notebookId}
                    offers={{ podcast, infographic }}
                    onGenerate={async (request) => {
                        const errors = await create(request);

                        if (errors === null) {
                            setCustomizing(null);
                        }

                        return errors;
                    }}
                />
            )}
            {howOpened && (
                <HowMadeDialog
                    item={howOpened}
                    open
                    onOpenChange={(o) => !o && setHowOpened(null)}
                />
            )}
        </div>
    );
}
