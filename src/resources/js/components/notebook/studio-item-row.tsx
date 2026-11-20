import {
    Info,
    Loader2,
    MoreVertical,
    Pencil,
    RefreshCw,
    RotateCcw,
    Trash2,
    TriangleAlert,
} from 'lucide-react';
import { useState } from 'react';
import { HowMadeDialog } from '@/components/notebook/how-made-dialog';
import { KindBadge } from '@/components/notebook/kind-badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { ModelBadges } from '@/components/notebook/model-badges';
import { reviewNote } from '@/lib/infographic-review';
import { KINDS, citedSourceCount, clock, whenLabel } from '@/lib/studio-kinds';
import type { StudioItem } from '@/types/studio';

export { KINDS, citedSourceCount, clock, whenLabel } from '@/lib/studio-kinds';

export function StudioItemRow({
    item,
    onOpen,
    onDelete,
    onRetry,
    onRename,
    onRegenerate,
}: {
    item: StudioItem;
    onOpen: () => void;
    onDelete: () => void;
    onRetry: () => void;
    onRename?: (title: string) => void;
    onRegenerate?: () => void;
}) {
    const [confirming, setConfirming] = useState(false);
    const [renaming, setRenaming] = useState(false);
    const [draft, setDraft] = useState(item.title);
    const [how, setHow] = useState(false);
    const kind = KINDS[item.kind] ?? KINDS.briefing;
    const sources = item.sources_count ?? citedSourceCount(item);
    const meta = [
        kind.label,
        whenLabel(item.created_at),
        sources > 0
            ? `${sources} ${sources === 1 ? 'source' : 'sources'}`
            : null,
        item.kind === 'audio' && typeof item.duration_seconds === 'number'
            ? clock(item.duration_seconds)
            : null,
    ].filter(Boolean);

    const save = () => {
        const title = draft.trim();

        setRenaming(false);

        if (title !== '' && title !== item.title) {
            onRename?.(title);
        }
    };
    const working = item.status === 'pending' || item.status === 'running';

    return (
        <li
            className="flex items-center gap-3 rounded-2xl bg-card-soft p-3 text-sm"
            data-testid="studio-item"
        >
            <KindBadge tile={kind.tile} />
            {renaming && (
                <input
                    autoFocus
                    aria-label={`Rename ${item.title}`}
                    data-testid="item-rename-input"
                    maxLength={120}
                    value={draft}
                    onChange={(e) => setDraft(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            save();
                        } else if (e.key === 'Escape') {
                            setRenaming(false);
                        }
                    }}
                    onBlur={save}
                    className="min-w-0 flex-1 rounded-lg border bg-panel px-2 py-1.5 text-body outline-none focus:ring-2 focus:ring-ring/50"
                />
            )}
            <button
                type="button"
                disabled={item.status !== 'done'}
                onClick={onOpen}
                className={`flex min-w-0 flex-1 flex-col text-left disabled:cursor-default ${renaming ? 'hidden' : ''}`}
            >
                <span className="truncate font-medium">{item.title}</span>
                <span
                    className="truncate text-caption text-muted-foreground"
                    data-testid="studio-meta"
                >
                    {meta.join(' · ')}
                </span>
                <ModelBadges item={item} className="my-0.5" />
                <span
                    className={`flex items-center gap-1 truncate text-caption ${item.status === 'failed' ? 'text-destructive' : item.status === 'done' ? 'text-emerald-700 dark:text-emerald-400' : 'text-muted-foreground'}`}
                    data-testid="studio-status"
                >
                    {working && <Loader2 className="size-3 animate-spin" />}
                    {item.status === 'failed' && (
                        <TriangleAlert className="size-3" />
                    )}
                    {item.status === 'done' && 'Ready. Click to open'}
                    {item.status === 'pending' &&
                        'Generating: waiting to start…'}
                    {item.status === 'running' &&
                        `Generating: ${item.step ?? 'working'}${item.step_total ? ` (${item.step_n}/${item.step_total})` : ''}…`}
                    {item.status === 'failed' && (item.error ?? 'It failed.')}
                </span>
                {item.status === 'done' && reviewNote(item) && (
                    <span
                        className={`truncate text-caption ${reviewNote(item)?.verdict === 'bad' ? 'text-amber-700 dark:text-amber-400' : 'text-muted-foreground'}`}
                        data-testid="studio-review"
                    >
                        {reviewNote(item)?.short}
                    </span>
                )}
            </button>
            {item.status === 'failed' && (
                <Button
                    variant="outline"
                    size="sm"
                    className="shrink-0 rounded-full"
                    onClick={onRetry}
                    data-testid="item-retry"
                >
                    <RotateCcw /> Retry
                </Button>
            )}
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-8 shrink-0"
                        aria-label={`Options for ${item.title}`}
                        data-testid="item-menu"
                    >
                        <MoreVertical />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem
                        onSelect={() => {
                            setDraft(item.title);
                            setRenaming(true);
                        }}
                        data-testid="item-rename"
                    >
                        <Pencil /> Rename
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        onSelect={() => onRegenerate?.()}
                        disabled={
                            item.status !== 'done' && item.status !== 'failed'
                        }
                        data-testid="item-regenerate"
                    >
                        <RefreshCw /> Make it again
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        onSelect={() => setHow(true)}
                        data-testid="item-how"
                    >
                        <Info /> How this was made
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        variant="destructive"
                        onSelect={() => setConfirming(true)}
                        data-testid="item-delete"
                    >
                        <Trash2 /> Delete
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <HowMadeDialog item={item} open={how} onOpenChange={setHow} />

            <Dialog open={confirming} onOpenChange={setConfirming}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Delete this {kind.label.toLowerCase()}?
                        </DialogTitle>
                        <DialogDescription>
                            &ldquo;{item.title}&rdquo; will be deleted. This
                            cannot be undone.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setConfirming(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            data-testid="confirm-delete"
                            onClick={() => {
                                setConfirming(false);
                                onDelete();
                            }}
                        >
                            Delete
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </li>
    );
}
