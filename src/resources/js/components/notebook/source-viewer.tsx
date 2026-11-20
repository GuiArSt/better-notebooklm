import { ArrowLeft, ExternalLink } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { getJson } from '@/lib/http';
import { retrySource } from '@/lib/retry-source';
import type { Passage } from '@/types/app';

type Loaded = {
    id: number;
    title: string;
    kind: string;
    status: string;
    error: string | null;
    origin_url: string | null;
    summary: string | null;
    content: string | null;
    pages: { page: number; start: number; end: number }[] | null;
};

/** The text cut into pieces at page boundaries, each piece split around the highlighted characters. */
function pieces(
    content: string,
    pages: Loaded['pages'],
    hl: { start: number; end: number } | null,
) {
    const ranges = pages?.length
        ? pages.map((p) => ({
              label: `Page ${p.page}`,
              start: p.start,
              end: p.end,
          }))
        : [{ label: null, start: 0, end: content.length }];

    return ranges.map((r) => {
        const text = content.slice(r.start, r.end);
        const a = hl ? Math.max(hl.start, r.start) - r.start : 0;
        const b = hl ? Math.min(hl.end, r.end) - r.start : 0;

        return b > a
            ? {
                  label: r.label,
                  before: text.slice(0, a),
                  mark: text.slice(a, b),
                  after: text.slice(b),
              }
            : { label: r.label, before: text, mark: '', after: '' };
    });
}

/** A source's own text, with its summary; opened from the list or from a citation, which highlights the passage. */
export function SourceViewer({
    notebookId,
    sourceId,
    passage,
    onBack,
}: {
    notebookId: number;
    sourceId: number;
    passage: Passage | null;
    onBack: () => void;
}) {
    const [source, setSource] = useState<Loaded | null>(null);
    const [error, setError] = useState<string | null>(null);
    const mark = useRef<HTMLElement>(null);

    useEffect(() => {
        let live = true;
        setSource(null);
        setError(null);
        getJson<Loaded>(`/notebooks/${notebookId}/sources/${sourceId}`)
            .then((s) => live && setSource(s))
            .catch((e: Error) => live && setError(e.message));

        return () => {
            live = false;
        };
    }, [notebookId, sourceId]);

    // A source being read again (after Retry) is looked at every two seconds until it is done or failed.
    const pending = source?.status === 'pending';

    useEffect(() => {
        if (!pending) {
            return;
        }

        const timer = setInterval(() => {
            getJson<Loaded>(`/notebooks/${notebookId}/sources/${sourceId}`)
                .then(setSource)
                .catch((e: Error) => setError(e.message));
        }, 2000);

        return () => clearInterval(timer);
    }, [pending, notebookId, sourceId]);

    useEffect(() => {
        mark.current?.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }, [source, passage]);

    const hl = passage && passage.sourceId === sourceId ? passage : null;

    return (
        <div
            className="flex min-h-0 flex-1 flex-col"
            data-testid="source-viewer"
        >
            <div className="flex items-center gap-2 border-b p-2">
                <Button
                    variant="ghost"
                    size="icon"
                    onClick={onBack}
                    aria-label="Back to sources"
                    className="size-8"
                >
                    <ArrowLeft />
                </Button>
                <h3 className="min-w-0 flex-1 truncate text-sm font-semibold">
                    {source?.title ?? 'Source'}
                </h3>
            </div>
            <div className="min-h-0 flex-1 space-y-4 overflow-y-auto p-4 text-sm">
                {error && <p className="text-destructive">{error}</p>}
                {!source && !error && (
                    <p className="text-muted-foreground">Loading…</p>
                )}
                {source && (
                    <>
                        {source.origin_url && (
                            <a
                                href={source.origin_url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="flex items-center gap-1 text-xs text-primary hover:underline"
                            >
                                <ExternalLink className="size-3" />{' '}
                                {source.origin_url}
                            </a>
                        )}
                        {source.summary && (
                            <div
                                className="rounded-xl bg-muted/50 p-3"
                                data-testid="source-summary"
                            >
                                <h4 className="mb-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                    Source guide
                                </h4>
                                <p className="leading-relaxed">
                                    {source.summary}
                                </p>
                            </div>
                        )}
                        {source.status === 'failed' && (
                            <div className="flex flex-wrap items-center gap-3">
                                <p className="text-destructive">
                                    {source.error ??
                                        'This source could not be read.'}
                                </p>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="rounded-full"
                                    onClick={() =>
                                        retrySource(notebookId, sourceId, () =>
                                            setSource({
                                                ...source,
                                                status: 'pending',
                                                error: null,
                                            }),
                                        )
                                    }
                                    data-testid="source-viewer-retry"
                                >
                                    Retry
                                </Button>
                            </div>
                        )}
                        {source.content ? (
                            <div
                                className="leading-relaxed whitespace-pre-wrap"
                                data-testid="source-text"
                            >
                                {pieces(source.content, source.pages, hl).map(
                                    (p, i) => (
                                        <div key={i}>
                                            {p.label && (
                                                <div className="my-3 text-xs font-semibold text-muted-foreground">
                                                    {p.label}
                                                </div>
                                            )}
                                            {p.before}
                                            {p.mark && (
                                                <mark
                                                    ref={mark}
                                                    data-testid="highlight"
                                                    className="rounded bg-yellow-200 px-0.5 text-foreground dark:bg-yellow-500/40"
                                                >
                                                    {p.mark}
                                                </mark>
                                            )}
                                            {p.after}
                                        </div>
                                    ),
                                )}
                            </div>
                        ) : (
                            source.status === 'pending' && (
                                <p className="text-muted-foreground">
                                    This source is still being read…
                                </p>
                            )
                        )}
                    </>
                )}
            </div>
        </div>
    );
}
