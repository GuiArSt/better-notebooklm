import { useState } from 'react';
import { downloadPptx } from '@/slides/pptx/download-pptx';
import type { Deck } from '@/slides/types';
import type { Citation } from '@/types/app';

/** "Download .pptx": builds the file in the browser; shows a busy state, and a message if it fails. */
export function PptxButton({
    deck,
    cite,
}: {
    deck: Deck;
    cite?: (id: string) => Citation | undefined;
}) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const run = async () => {
        setBusy(true);
        setError(null);
        try {
            await downloadPptx(deck, { cite });
        } catch (e) {
            setError(e instanceof Error ? e.message : 'The download failed.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <span className="inline-flex flex-col items-start gap-1">
            <button
                type="button"
                onClick={run}
                disabled={busy}
                aria-busy={busy}
                className="inline-flex h-8 items-center rounded-md border px-3 text-sm font-medium hover:bg-accent disabled:opacity-60"
            >
                {busy ? 'Building…' : 'Download .pptx'}
            </button>
            {error && (
                <span role="alert" className="text-sm text-destructive">
                    Could not build the .pptx: {error}
                </span>
            )}
        </span>
    );
}
