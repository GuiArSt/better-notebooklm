import { useState } from 'react';
import { NotebookGlyph } from '@/icons/notebook/notebook-glyph';
import { cn } from '@/lib/utils';

/**
 * A notebook's picture: its generated icon when that is done (the owner-only route), otherwise the drawn icon for its
 * stored emoji (D-059: no emoji is shown), in the same square box either way, so nothing jumps when the icon arrives.
 * A missing image falls back to the drawn icon too.
 */
export function NotebookIcon({
    id,
    emoji,
    status,
    size = 'md',
    className,
}: {
    id: number;
    emoji: string;
    status?: string | null;
    size?: 'sm' | 'md' | 'lg' | 'xl';
    className?: string;
}) {
    // The id whose image failed to load, so a different notebook starts from its own image again.
    const [brokenId, setBrokenId] = useState<number | null>(null);
    const image = status === 'done' && brokenId !== id;
    const box = {
        sm: 'size-8 text-2xl rounded-lg',
        md: 'size-12 text-4xl rounded-xl',
        lg: 'size-14 text-5xl rounded-2xl',
        xl: 'size-20 text-6xl rounded-3xl',
    }[size];

    return (
        <span
            data-testid="notebook-icon"
            data-kind={image ? 'image' : 'glyph'}
            className={cn(
                'relative flex shrink-0 items-center justify-center overflow-hidden leading-none',
                !image && 'bg-panel text-foreground',
                box,
                className,
            )}
        >
            {image ? (
                <img
                    src={`/notebooks/${id}/icon`}
                    alt=""
                    width={256}
                    height={256}
                    loading="lazy"
                    className="size-full object-cover"
                    onError={() => setBrokenId(id)}
                />
            ) : (
                <NotebookGlyph emoji={emoji} className="size-[58%]" />
            )}
        </span>
    );
}
