import { router } from '@inertiajs/react';
import {
    ChevronDown,
    ChevronRight,
    Plus,
    StickyNote,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { CitedText } from '@/components/notebook/citation';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import type { Citation, Note, Passage } from '@/types/app';

/** Notes: your own writing, or answers saved from chat. A checked note is used as a source by the chat. */
export function NotesSection({
    notebookId,
    notes,
    onOpenCitation,
}: {
    notebookId: number;
    notes: Note[];
    onOpenCitation: (passage: Passage, citation?: Citation) => void;
}) {
    const [open, setOpen] = useState<number | null>(null);

    const add = () =>
        router.post(
            `/notebooks/${notebookId}/notes`,
            { title: 'New note', content: '' },
            { preserveScroll: true },
        );

    return (
        <section data-testid="notes">
            <div className="mb-1 flex items-center justify-between px-1">
                <h3 className="flex items-center gap-2 text-sm font-semibold">
                    <StickyNote className="size-4" /> Notes
                </h3>
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={add}
                    data-testid="add-note"
                >
                    <Plus /> Add note
                </Button>
            </div>
            {notes.length === 0 && (
                <p className="px-1 text-sm text-muted-foreground">
                    Write a note, or use &ldquo;Save to note&rdquo; under an
                    answer in the chat.
                </p>
            )}
            <ul className="flex flex-col gap-2">
                {notes.map((n) => (
                    <NoteItem
                        key={n.id}
                        notebookId={notebookId}
                        note={n}
                        expanded={open === n.id}
                        onToggle={() => setOpen(open === n.id ? null : n.id)}
                        onOpenCitation={onOpenCitation}
                    />
                ))}
            </ul>
        </section>
    );
}

function NoteItem({
    notebookId,
    note,
    expanded,
    onToggle,
    onOpenCitation,
}: {
    notebookId: number;
    note: Note;
    expanded: boolean;
    onToggle: () => void;
    onOpenCitation: (passage: Passage, citation?: Citation) => void;
}) {
    const url = `/notebooks/${notebookId}/notes/${note.id}`;
    const [title, setTitle] = useState(note.title);
    const [content, setContent] = useState(note.content);
    const dirty = title !== note.title || content !== note.content;
    const Chevron = expanded ? ChevronDown : ChevronRight;

    const save = () =>
        router.patch(url, { title, content }, { preserveScroll: true });

    return (
        <li className="rounded-lg border" data-testid="note">
            <div className="flex items-center gap-2 p-2">
                <button
                    type="button"
                    onClick={onToggle}
                    className="flex min-w-0 flex-1 items-center gap-1 text-left text-sm"
                >
                    <Chevron className="size-4 shrink-0" />
                    <span className="truncate">
                        {note.title || 'Untitled note'}
                    </span>
                </button>
                <label
                    className="flex items-center gap-1 text-xs text-muted-foreground"
                    title="Use this note as a source"
                >
                    Source
                    <Checkbox
                        checked={note.checked}
                        disabled={note.content.trim() === ''}
                        onCheckedChange={(v) =>
                            router.patch(
                                url,
                                { checked: v === true },
                                { preserveScroll: true },
                            )
                        }
                        aria-label={`Use note ${note.title} as a source`}
                    />
                </label>
            </div>
            {expanded && (
                <div className="space-y-2 border-t p-2">
                    {note.from_chat && note.citations ? (
                        <div className="text-sm">
                            <CitedText
                                text={note.content}
                                citations={note.citations}
                                onOpen={onOpenCitation}
                            />
                        </div>
                    ) : (
                        <>
                            <Input
                                aria-label="Note title"
                                value={title}
                                onChange={(e) => setTitle(e.target.value)}
                            />
                            <textarea
                                className="min-h-28 w-full rounded-md border bg-transparent p-2 text-sm"
                                aria-label="Note text"
                                placeholder="Write your note"
                                value={content}
                                onChange={(e) => setContent(e.target.value)}
                            />
                        </>
                    )}
                    <div className="flex justify-between">
                        <Button
                            variant="ghost"
                            size="sm"
                            className="text-destructive"
                            onClick={() =>
                                router.delete(url, { preserveScroll: true })
                            }
                        >
                            <Trash2 /> Delete
                        </Button>
                        {!(note.from_chat && note.citations) && (
                            <Button
                                size="sm"
                                disabled={!dirty}
                                onClick={save}
                                data-testid="save-note"
                            >
                                Save
                            </Button>
                        )}
                    </div>
                </div>
            )}
        </li>
    );
}
