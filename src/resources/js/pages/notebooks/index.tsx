import { Head, Link, router } from '@inertiajs/react';
import {
    LayoutGrid,
    List,
    MoreVertical,
    Pencil,
    Plus,
    Search,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { NotebookIcon } from '@/components/notebook-icon';
import { NotebookGlyph } from '@/icons/notebook/notebook-glyph';
import { NotebookDialog } from '@/components/notebook-dialog';
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
import { GuidedChecklist } from '@/demo/guided-checklist';
import type { NotebookSummary } from '@/types/app';

type Item = NotebookSummary & { sources_count: number; updated_at: string };

type Props = {
    notebooks: Item[];
    /** null once the user has their own copy: it is listed under Recent, so the card is not shown a second time. */
    featured: {
        slug: string;
        emoji: string;
        title: string;
        blurb: string;
    } | null;
};

export default function NotebooksIndex({ notebooks, featured }: Props) {
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState<Item | null>(null);
    const [deleting, setDeleting] = useState<Item | null>(null);

    const openFeatured = () => router.post('/notebooks/featured');

    const [query, setQuery] = useState('');
    const [view, setView] = useState<'grid' | 'list'>('grid');
    const shown = notebooks.filter((n) =>
        n.title.toLowerCase().includes(query.trim().toLowerCase()),
    );
    const date = (iso: string) =>
        new Date(iso).toLocaleDateString('en', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
        });

    const menu = (n: Item, className: string) => (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className={className}
                    aria-label={`Options for ${n.title}`}
                >
                    <MoreVertical />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuItem onSelect={() => setEditing(n)}>
                    <Pencil /> Rename or change icon
                </DropdownMenuItem>
                <DropdownMenuItem
                    variant="destructive"
                    onSelect={() => setDeleting(n)}
                >
                    <Trash2 /> Delete
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );

    return (
        <>
            <Head title="Notebooks" />
            <div className="bg-constellation-sparse min-h-full">
                <div className="mx-auto flex max-w-[92rem] flex-col gap-8 px-4 py-4 sm:px-8">
                    {/* The toolbar: filter pills, a pill search, the view toggle. */}
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="rounded-full bg-bubble px-4 py-2 text-body font-medium">
                            All
                        </span>
                        {['Discover', 'Collections'].map((label) => (
                            <span
                                key={label}
                                aria-disabled="true"
                                className="cursor-not-allowed rounded-full px-4 py-2 text-body text-muted-foreground opacity-60"
                            >
                                <span className="flex flex-col items-center leading-tight">
                                    {label}
                                    <span className="text-[10px]">
                                        not in this demo
                                    </span>
                                </span>
                            </span>
                        ))}
                        <div className="ml-auto flex items-center gap-2">
                            <label className="flex items-center gap-2 rounded-full bg-panel px-4 py-2 text-body">
                                <Search className="size-4 text-muted-foreground" />
                                <input
                                    className="w-32 bg-transparent outline-none sm:w-56"
                                    placeholder="Search notebooks"
                                    aria-label="Search notebooks"
                                    value={query}
                                    onChange={(e) => setQuery(e.target.value)}
                                />
                            </label>
                            <div className="flex rounded-full bg-panel p-1">
                                {(
                                    [
                                        ['grid', LayoutGrid, 'Grid view'],
                                        ['list', List, 'List view'],
                                    ] as const
                                ).map(([id, Icon, label]) => (
                                    <button
                                        key={id}
                                        type="button"
                                        aria-label={label}
                                        aria-pressed={view === id}
                                        onClick={() => setView(id)}
                                        className={`rounded-full p-2 ${view === id ? 'bg-bubble' : 'text-muted-foreground'}`}
                                    >
                                        <Icon className="size-4" />
                                    </button>
                                ))}
                            </div>
                        </div>
                    </div>

                    <GuidedChecklist />

                    {featured && (
                        <section>
                            <h2 className="mb-4 text-title font-normal">
                                Featured notebook
                            </h2>
                            <button
                                type="button"
                                onClick={openFeatured}
                                data-testid="featured"
                                className="relative flex h-52 w-full max-w-[18.5rem] flex-col justify-end overflow-hidden rounded-card bg-gradient-to-br from-slate-900 via-indigo-900 to-teal-800 p-5 text-left text-white transition hover:shadow-lg"
                            >
                                <NotebookGlyph
                                    emoji={featured.emoji}
                                    className="absolute -top-3 -right-3 size-36 opacity-25"
                                />
                                <span className="text-title leading-snug font-normal">
                                    {featured.title}
                                </span>
                                <span className="mt-1 line-clamp-2 text-caption text-white/80">
                                    {featured.blurb}
                                </span>
                            </button>
                        </section>
                    )}

                    <section>
                        <div className="mb-4 flex items-center justify-between">
                            <h1 className="text-title font-normal">
                                Recent notebooks
                            </h1>
                            <Button
                                onClick={() => setCreating(true)}
                                data-testid="new-notebook"
                                className="rounded-full bg-cta px-5 text-cta-foreground hover:bg-cta/80"
                            >
                                <Plus /> New notebook
                            </Button>
                        </div>

                        {view === 'grid' ? (
                            <div
                                className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5"
                                data-testid="notebooks"
                            >
                                {shown.map((n) => (
                                    <div
                                        key={n.id}
                                        className="group relative flex min-h-48 flex-col justify-between rounded-card bg-card-soft p-5 transition hover:shadow-md"
                                        data-testid="notebook-card"
                                    >
                                        <Link
                                            href={`/notebooks/${n.id}`}
                                            className="absolute inset-0 rounded-card"
                                            aria-label={`Open ${n.title}`}
                                        />
                                        <NotebookIcon
                                            id={n.id}
                                            emoji={n.emoji}
                                            status={n.icon_status}
                                            size="md"
                                        />
                                        <div>
                                            <h3 className="line-clamp-2 text-title leading-snug font-normal">
                                                {n.title}
                                            </h3>
                                            <p className="text-body text-muted-foreground">
                                                {date(n.updated_at)} ·{' '}
                                                {n.sources_count}{' '}
                                                {n.sources_count === 1
                                                    ? 'source'
                                                    : 'sources'}
                                            </p>
                                        </div>
                                        {menu(
                                            n,
                                            'absolute top-3 right-3 size-8',
                                        )}
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div
                                className="overflow-hidden rounded-3xl bg-panel"
                                data-testid="notebooks"
                            >
                                <table className="w-full text-body">
                                    <thead className="text-left text-caption text-muted-foreground">
                                        <tr>
                                            <th className="p-4">Title</th>
                                            <th className="p-4">Sources</th>
                                            <th className="hidden p-4 sm:table-cell">
                                                Updated
                                            </th>
                                            <th className="w-12 p-4" />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {shown.map((n) => (
                                            <tr
                                                key={n.id}
                                                className="border-t border-tile-border"
                                                data-testid="notebook-card"
                                            >
                                                <td className="p-4">
                                                    <Link
                                                        href={`/notebooks/${n.id}`}
                                                        className="flex items-center gap-3"
                                                        aria-label={`Open ${n.title}`}
                                                    >
                                                        <NotebookIcon
                                                            id={n.id}
                                                            emoji={n.emoji}
                                                            status={
                                                                n.icon_status
                                                            }
                                                            size="sm"
                                                        />
                                                        {n.title}
                                                    </Link>
                                                </td>
                                                <td className="p-4">
                                                    {n.sources_count}
                                                </td>
                                                <td className="hidden p-4 sm:table-cell">
                                                    {date(n.updated_at)}
                                                </td>
                                                <td className="p-4">
                                                    {menu(n, 'size-8')}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                        {shown.length === 0 && (
                            <p className="py-10 text-center text-body text-muted-foreground">
                                {notebooks.length === 0
                                    ? 'No notebooks yet. Make your first one.'
                                    : 'No notebook matches that search.'}
                            </p>
                        )}
                    </section>
                </div>
            </div>

            {creating && (
                <NotebookDialog
                    open
                    onOpenChange={(o) => !o && setCreating(false)}
                />
            )}
            {editing && (
                <NotebookDialog
                    key={editing.id}
                    notebook={editing}
                    open
                    onOpenChange={(o) => !o && setEditing(null)}
                />
            )}
            <Dialog
                open={deleting !== null}
                onOpenChange={(o) => !o && setDeleting(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete this notebook?</DialogTitle>
                        <DialogDescription>
                            &ldquo;{deleting?.title}&rdquo; and all its sources
                            and notes will be deleted. This cannot be undone.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setDeleting(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() =>
                                deleting &&
                                router.delete(`/notebooks/${deleting.id}`, {
                                    onFinish: () => setDeleting(null),
                                })
                            }
                        >
                            Delete
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
