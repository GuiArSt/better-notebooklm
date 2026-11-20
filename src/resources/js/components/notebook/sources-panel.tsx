import { router } from '@inertiajs/react';
import {
    FileText,
    Globe,
    Loader2,
    MoreVertical,
    Plus,
    Trash2,
    TriangleAlert,
} from 'lucide-react';
import { useState } from 'react';
import { AddSourceDialog } from '@/components/notebook/add-source-dialog';
import { NotesSection } from '@/components/notebook/notes-section';
import { SourceViewer } from '@/components/notebook/source-viewer';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SourceIcon } from '@/icons/sources/source-icon';
import { largeNotice } from '@/lib/large-source';
import { retrySource } from '@/lib/retry-source';
import type { Citation, Note, Passage, Source } from '@/types/app';

export function SourcesPanel({
    notebookId,
    sources,
    notes,
    maxChars,
    viewing,
    passage,
    onView,
    onCloseViewer,
    onOpenCitation,
}: {
    notebookId: number;
    sources: Source[];
    notes: Note[];
    maxChars: number;
    viewing: number | null;
    passage: Passage | null;
    onView: (sourceId: number) => void;
    onCloseViewer: () => void;
    onOpenCitation: (passage: Passage, citation?: Citation) => void;
}) {
    const [adding, setAdding] = useState(false);

    const setChecked = (source: Source, checked: boolean) =>
        router.patch(
            `/notebooks/${notebookId}/sources/${source.id}`,
            { checked },
            {
                only: ['sources'],
                preserveScroll: true,
                preserveState: true,
                async: true,
            },
        );

    const allChecked = sources.length > 0 && sources.every((s) => s.checked);

    const toggleAll = () => {
        const targets = sources.filter((s) => s.checked === allChecked);

        const next = (i: number) => {
            if (i >= targets.length) {
                return;
            }

            router.patch(
                `/notebooks/${notebookId}/sources/${targets[i].id}`,
                { checked: !allChecked },
                {
                    only: ['sources'],
                    preserveScroll: true,
                    preserveState: true,
                    onFinish: () => next(i + 1),
                },
            );
        };

        next(0);
    };

    if (viewing !== null) {
        return (
            <SourceViewer
                notebookId={notebookId}
                sourceId={viewing}
                passage={passage}
                onBack={onCloseViewer}
            />
        );
    }

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <div className="flex-1 space-y-4 overflow-y-auto p-3">
                <div className="grid grid-cols-2 gap-2">
                    <Button
                        variant="secondary"
                        className="rounded-full bg-card-soft hover:bg-bubble"
                        onClick={() => setAdding(true)}
                        data-testid="add-source"
                    >
                        <Plus /> Add sources
                    </Button>
                    <Button
                        variant="secondary"
                        className="rounded-full bg-card-soft hover:bg-bubble"
                        onClick={() =>
                            router.post(
                                `/notebooks/${notebookId}/notes`,
                                { title: 'New note', content: '' },
                                { preserveScroll: true },
                            )
                        }
                        data-testid="create-note"
                    >
                        <Plus /> Create note
                    </Button>
                </div>

                <div
                    aria-disabled="true"
                    className="cursor-not-allowed space-y-3 rounded-3xl bg-card-soft p-4 opacity-60"
                >
                    <p className="text-body text-muted-foreground">
                        Search the web for new sources
                    </p>
                    <div className="flex flex-wrap items-center gap-2 text-caption">
                        <span className="flex items-center gap-1 rounded-full bg-panel px-3 py-1.5">
                            <Globe className="size-3.5" /> Web
                        </span>
                        <span className="rounded-full bg-panel px-3 py-1.5 whitespace-nowrap">
                            Fast Research
                        </span>
                        <span className="w-full">not in this demo</span>
                    </div>
                </div>

                {sources.length === 0 ? (
                    <div
                        className="flex flex-col items-center gap-1 px-2 pt-10 text-center text-body text-muted-foreground"
                        data-testid="sources-empty"
                    >
                        <FileText className="mb-1 size-6" />
                        <p className="font-medium text-foreground">
                            Saved sources will appear here
                        </p>
                        <p>
                            Add a PDF, a web page or pasted text, then ask
                            questions about it.
                        </p>
                        <button
                            type="button"
                            className="underline"
                            onClick={() => setAdding(true)}
                        >
                            Add a source
                        </button>
                    </div>
                ) : (
                    <div>
                        <label className="flex items-center justify-between px-2 pb-1 text-xs text-muted-foreground">
                            Select all sources
                            <Checkbox
                                checked={allChecked}
                                onCheckedChange={toggleAll}
                                aria-label="Select all sources"
                            />
                        </label>
                        <ul className="flex flex-col" data-testid="sources">
                            {sources.map((s) => {
                                return (
                                    <li
                                        key={s.id}
                                        className="flex items-center gap-2 rounded-2xl p-2.5 text-body hover:bg-card-soft"
                                    >
                                        <div className="min-w-0 flex-1">
                                            <button
                                                type="button"
                                                onClick={() => onView(s.id)}
                                                className="flex w-full min-w-0 items-center gap-2 text-left"
                                                data-testid="source-open"
                                            >
                                                <SourceIcon
                                                    kind={s.kind}
                                                    className="size-4 shrink-0"
                                                />
                                                <span className="truncate">
                                                    {s.title}
                                                </span>
                                            </button>
                                            {largeNotice(s) && (
                                                <p
                                                    className="pl-6 text-caption text-muted-foreground"
                                                    data-testid="source-large"
                                                >
                                                    {largeNotice(s)}
                                                </p>
                                            )}
                                        </div>
                                        {s.status === 'pending' && (
                                            <Loader2
                                                className="size-4 shrink-0 animate-spin text-muted-foreground"
                                                aria-label="Reading"
                                            />
                                        )}
                                        {s.status === 'failed' && (
                                            <>
                                                <span
                                                    title={s.error ?? 'failed'}
                                                    data-testid="source-failed"
                                                >
                                                    <TriangleAlert className="size-4 shrink-0 text-destructive" />
                                                </span>
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    className="h-7 shrink-0 rounded-full px-2.5"
                                                    onClick={() =>
                                                        retrySource(
                                                            notebookId,
                                                            s.id,
                                                        )
                                                    }
                                                    data-testid="source-retry"
                                                >
                                                    Retry
                                                </Button>
                                            </>
                                        )}
                                        <span
                                            className="sr-only"
                                            data-testid="source-status"
                                        >
                                            {s.status}
                                        </span>
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-7 shrink-0"
                                                    aria-label={`Options for ${s.title}`}
                                                >
                                                    <MoreVertical />
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                <DropdownMenuItem
                                                    variant="destructive"
                                                    onSelect={() =>
                                                        router.delete(
                                                            `/notebooks/${notebookId}/sources/${s.id}`,
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    <Trash2 /> Remove source
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                        <Checkbox
                                            checked={s.checked}
                                            onCheckedChange={(v) =>
                                                setChecked(s, v === true)
                                            }
                                            aria-label={`Use ${s.title}`}
                                        />
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                )}

                <NotesSection
                    notebookId={notebookId}
                    notes={notes}
                    onOpenCitation={onOpenCitation}
                />
            </div>

            <AddSourceDialog
                notebookId={notebookId}
                maxChars={maxChars}
                open={adding}
                onOpenChange={setAdding}
            />
        </div>
    );
}
