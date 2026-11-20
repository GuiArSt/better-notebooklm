import { router } from '@inertiajs/react';
import {
    FileText,
    Globe,
    HardDrive,
    Headphones,
    Image,
    Type,
    Youtube,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { WEB_SOURCE_NOTE } from '@/lib/web-source-note';
import { cn } from '@/lib/utils';

type Mode = 'pdf' | 'web' | 'text';

const LIVE: { mode: Mode; label: string; hint: string; icon: LucideIcon }[] = [
    {
        mode: 'pdf',
        label: 'PDF or text file',
        hint: 'Upload a file',
        icon: FileText,
    },
    { mode: 'web', label: 'Web page', hint: 'Paste a link', icon: Globe },
    {
        mode: 'text',
        label: 'Pasted text',
        hint: 'Paste what you have',
        icon: Type,
    },
];

const GREYED: { label: string; icon: LucideIcon }[] = [
    { label: 'YouTube', icon: Youtube },
    { label: 'Audio file', icon: Headphones },
    { label: 'Image', icon: Image },
    { label: 'Google Drive', icon: HardDrive },
];

export function AddSourceDialog({
    notebookId,
    maxChars,
    open,
    onOpenChange,
}: {
    notebookId: number;
    maxChars: number;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [mode, setMode] = useState<Mode | null>(null);
    const [title, setTitle] = useState('');
    const [content, setContent] = useState('');
    const [url, setUrl] = useState('');
    const [file, setFile] = useState<File | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const fileInput = useRef<HTMLInputElement>(null);

    const reset = () => {
        setMode(null);
        setTitle('');
        setContent('');
        setUrl('');
        setFile(null);
        setErrors({});
    };

    const ready =
        (mode === 'pdf' && file !== null) ||
        (mode === 'web' && url.trim() !== '') ||
        (mode === 'text' && title.trim() !== '' && content.trim() !== '');

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        const data =
            mode === 'pdf'
                ? { file, title }
                : mode === 'web'
                  ? { url, title }
                  : { title, content };

        setBusy(true);
        router.post(`/notebooks/${notebookId}/sources`, data, {
            forceFormData: mode === 'pdf',
            preserveScroll: true,
            onError: (errs) => setErrors(errs),
            onSuccess: () => {
                reset();
                onOpenChange(false);
            },
            onFinish: () => setBusy(false),
        });
    };

    const error = errors.file ?? errors.url ?? errors.content ?? errors.title;

    return (
        <Dialog
            open={open}
            onOpenChange={(o) => {
                if (!o) {
                    reset();
                }

                onOpenChange(o);
            }}
        >
            <DialogContent className="max-w-xl">
                <DialogHeader>
                    <DialogTitle>Add a source</DialogTitle>
                    <DialogDescription>
                        Sources are what the chat answers from. Pick a type.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
                    {LIVE.map(({ mode: m, label, hint, icon: Icon }) => (
                        <button
                            key={m}
                            type="button"
                            onClick={() => setMode(m)}
                            data-testid={`add-${m}`}
                            className={cn(
                                'flex min-w-0 flex-col items-center gap-1 rounded-xl border p-3 text-center text-sm hover:bg-accent',
                                mode === m && 'border-primary bg-accent',
                            )}
                        >
                            <Icon className="size-5" />
                            <span className="font-medium">{label}</span>
                            <span className="text-xs text-muted-foreground">
                                {hint}
                            </span>
                        </button>
                    ))}
                </div>
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    {GREYED.map(({ label, icon: Icon }) => (
                        <div
                            key={label}
                            aria-disabled="true"
                            className="flex min-w-0 cursor-not-allowed flex-col items-center gap-1 rounded-xl border border-dashed p-2 text-center text-xs text-muted-foreground opacity-60"
                        >
                            <Icon className="size-4" />
                            {label}
                            <span className="text-[10px]">
                                not in this demo
                            </span>
                        </div>
                    ))}
                </div>

                {mode && (
                    <form
                        onSubmit={submit}
                        className="flex min-w-0 flex-col gap-3"
                    >
                        {mode === 'pdf' && (
                            <>
                                <input
                                    ref={fileInput}
                                    type="file"
                                    accept=".pdf,.txt,.md,application/pdf,text/plain"
                                    className="hidden"
                                    data-testid="file-input"
                                    onChange={(e) =>
                                        setFile(e.target.files?.[0] ?? null)
                                    }
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => fileInput.current?.click()}
                                >
                                    {file
                                        ? file.name
                                        : 'Choose a PDF, .txt or .md file'}
                                </Button>
                            </>
                        )}
                        {mode === 'web' && (
                            <>
                                <Input
                                    type="url"
                                    placeholder="https://example.com/article"
                                    aria-label="Web address"
                                    value={url}
                                    onChange={(e) => setUrl(e.target.value)}
                                />
                                <p
                                    className="text-caption text-muted-foreground"
                                    data-testid="web-source-note"
                                >
                                    {WEB_SOURCE_NOTE}
                                </p>
                            </>
                        )}
                        {mode !== 'pdf' && mode !== 'web' && (
                            <Input
                                placeholder="Title"
                                aria-label="Title"
                                value={title}
                                onChange={(e) => setTitle(e.target.value)}
                            />
                        )}
                        {mode === 'text' && (
                            <textarea
                                className="min-h-40 rounded-md border bg-transparent p-2 text-sm"
                                placeholder="Paste text"
                                aria-label="Text"
                                maxLength={maxChars}
                                value={content}
                                onChange={(e) => setContent(e.target.value)}
                            />
                        )}
                        {error && (
                            <p className="text-sm text-destructive">{error}</p>
                        )}
                        <Button
                            type="submit"
                            disabled={busy || !ready}
                            data-testid="add-submit"
                        >
                            Add source
                        </Button>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}
