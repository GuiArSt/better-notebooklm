import { router } from '@inertiajs/react';
import { Copy, NotebookPen } from 'lucide-react';
import { CitedText } from '@/components/notebook/citation';
import { Button } from '@/components/ui/button';
import { useClipboard } from '@/hooks/use-clipboard';
import { stripCitations } from '@/lib/citations';
import type { Passage } from '@/types/app';
import type { StudioItem } from '@/types/studio';

/** A written report with numbered, hoverable citations that open the source at the passage. */
export function ReportViewer({
    item,
    onOpenCitation,
}: {
    item: StudioItem;
    onOpenCitation: (passage: Passage) => void;
}) {
    const [, copy] = useClipboard();
    const plain = stripCitations(item.content ?? '');

    return (
        <div className="space-y-4" data-testid="report-viewer">
            <div className="flex gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    onClick={() => void copy(plain)}
                >
                    <Copy /> Copy text
                </Button>
                <SaveAsNote item={item} />
            </div>
            <p className="text-xs text-muted-foreground" data-testid="ai-label">
                AI-generated
            </p>
            <div className="text-sm">
                <CitedText
                    text={item.content ?? ''}
                    citations={item.citations}
                    onOpen={onOpenCitation}
                />
            </div>
        </div>
    );
}

function SaveAsNote({ item }: { item: StudioItem }) {
    return (
        <Button
            variant="outline"
            size="sm"
            onClick={() =>
                router.post(
                    `/notebooks/${item.notebook_id}/notes`,
                    {
                        title: item.title,
                        content: item.content ?? '',
                        citations: item.citations ?? [],
                        from_chat: true,
                    },
                    { preserveScroll: true },
                )
            }
        >
            <NotebookPen /> Save to note
        </Button>
    );
}
