import { router } from '@inertiajs/react';
import { useState } from 'react';
import { EmojiPicker } from '@/components/emoji-picker';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import type { NotebookSummary } from '@/types/app';

/** Create a notebook (no `notebook`) or rename it and change its icon. */
export function NotebookDialog({
    notebook,
    open,
    onOpenChange,
}: {
    notebook?: NotebookSummary;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [title, setTitle] = useState(notebook?.title ?? '');
    const [emoji, setEmoji] = useState(notebook?.emoji ?? '📓');
    const [busy, setBusy] = useState(false);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        setBusy(true);

        const options = {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
            onFinish: () => setBusy(false),
        };
        const data = { title: title.trim() || 'Untitled notebook', emoji };

        if (notebook) {
            router.patch(`/notebooks/${notebook.id}`, data, options);
        } else {
            router.post('/notebooks', data, options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>
                            {notebook ? 'Edit notebook' : 'New notebook'}
                        </DialogTitle>
                        <DialogDescription>
                            Give it a name and an icon so you can find it again.
                        </DialogDescription>
                    </DialogHeader>
                    <Input
                        autoFocus
                        aria-label="Notebook name"
                        placeholder="Untitled notebook"
                        maxLength={120}
                        value={title}
                        onChange={(e) => setTitle(e.target.value)}
                    />
                    <EmojiPicker value={emoji} onChange={setEmoji} />
                    <DialogFooter>
                        <Button type="submit" disabled={busy}>
                            {notebook ? 'Save' : 'Create notebook'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
