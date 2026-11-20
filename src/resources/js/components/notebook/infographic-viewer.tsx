import { Download, ExternalLink } from 'lucide-react';
import { CitationChip } from '@/components/notebook/citation';
import { Button } from '@/components/ui/button';
import { reviewNote } from '@/lib/infographic-review';
import type { Citation, Passage } from '@/types/app';
import type { StudioItem } from '@/types/studio';

/** The citation behind a "P12" reference, if the server kept it (a reference it dropped has no chip). */
export const citationOf = (
    ref: string,
    citations: Citation[] | null,
): { index: number; citation: Citation } | null => {
    const id = Number(ref.replace(/^P/i, ''));
    const index = citations?.findIndex((c) => c.id === id) ?? -1;

    return index >= 0 && citations
        ? { index, citation: citations[index] }
        : null;
};

/** One drawn picture, a download, and the facts it shows, each with the passages behind it. */
export function InfographicViewer({
    item,
    onOpenCitation,
}: {
    item: StudioItem;
    onOpenCitation: (passage: Passage) => void;
}) {
    const image = item.image_url;
    const note = reviewNote(item);

    if (item.status !== 'done' || !image) {
        return (
            <p
                className="text-body text-muted-foreground"
                data-testid="infographic-pending"
            >
                {item.status === 'failed'
                    ? (item.error ?? 'This infographic could not be made.')
                    : 'The picture is still being drawn.'}
            </p>
        );
    }

    return (
        <div className="space-y-3" data-testid="infographic-viewer">
            {/* The showcase: the picture is most of the dialog, and a click opens it full size. */}
            <a
                href={image}
                target="_blank"
                rel="noopener noreferrer"
                title="Open full size"
                className="block cursor-zoom-in"
                data-testid="infographic-open"
            >
                <img
                    src={image}
                    alt={item.infographic?.title ?? item.title}
                    className="mx-auto max-h-[78vh] w-full rounded-2xl border object-contain"
                    data-testid="infographic-image"
                />
            </a>
            <div className="flex flex-wrap items-center gap-2">
                <Button
                    asChild
                    variant="outline"
                    className="w-fit rounded-full"
                >
                    <a
                        href={`${image}?download=1`}
                        data-testid="infographic-download"
                    >
                        <Download /> Download
                    </a>
                </Button>
                <Button asChild variant="ghost" className="w-fit rounded-full">
                    <a href={image} target="_blank" rel="noopener noreferrer">
                        <ExternalLink /> Open full size
                    </a>
                </Button>
            </div>
            {note?.verdict === 'bad' && (
                <div
                    className="rounded-2xl border border-amber-500/40 bg-amber-500/10 p-3 text-body"
                    role="note"
                    data-testid="infographic-review-bad"
                >
                    <p className="font-medium">{note.heading}</p>
                    <ul className="list-disc pl-5">
                        {note.problems.map((problem) => (
                            <li key={problem}>{problem}</li>
                        ))}
                    </ul>
                </div>
            )}
            {note?.verdict === 'unavailable' && (
                <p
                    className="text-caption text-muted-foreground"
                    data-testid="infographic-review-unavailable"
                >
                    {note.text}
                </p>
            )}
            {item.infographic && item.infographic.points.length > 0 && (
                // Closed by default: people open an infographic to see the picture. Open, the points read as a list to scan.
                <details
                    className="rounded-2xl bg-card-soft p-4"
                    data-testid="infographic-text"
                >
                    <summary className="cursor-pointer text-body font-medium select-none">
                        Text and sources
                    </summary>
                    <ol
                        className="mt-4 space-y-4 text-body"
                        data-testid="infographic-points"
                    >
                        {item.infographic.points.map((point, i) => (
                            <li
                                key={point.heading}
                                className="grid grid-cols-[2rem_minmax(0,1fr)] gap-3"
                            >
                                <span
                                    className="flex size-8 items-center justify-center rounded-full bg-bubble text-caption font-semibold"
                                    aria-hidden="true"
                                >
                                    {i + 1}
                                </span>
                                <div className="min-w-0 space-y-1">
                                    <p className="flex flex-wrap items-center gap-2">
                                        <span className="font-semibold">
                                            {point.heading}
                                        </span>
                                        {point.figure && (
                                            <span
                                                className="rounded-full bg-amber-100 px-2.5 py-0.5 font-semibold text-amber-900 tabular-nums dark:bg-amber-500/20 dark:text-amber-200"
                                                data-testid="infographic-figure"
                                            >
                                                {point.figure}
                                            </span>
                                        )}
                                    </p>
                                    <p className="text-muted-foreground">
                                        {point.text}
                                    </p>
                                    <p className="flex flex-wrap items-center gap-1">
                                        {point.refs.map((ref) => {
                                            const found = citationOf(
                                                ref,
                                                item.citations,
                                            );

                                            return found ? (
                                                <CitationChip
                                                    key={ref}
                                                    index={found.index}
                                                    citation={found.citation}
                                                    onOpen={onOpenCitation}
                                                />
                                            ) : null;
                                        })}
                                    </p>
                                </div>
                            </li>
                        ))}
                    </ol>
                </details>
            )}
            <p
                className="text-caption text-muted-foreground"
                data-testid="ai-label"
            >
                AI-generated picture. Check the facts against the sources.
            </p>
        </div>
    );
}
