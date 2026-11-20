import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { Markdown } from '@/lib/markdown';
import type { Citation, Passage } from '@/types/app';

/** A numbered chip: hover shows the quoted passage, click opens the source at that passage. */
export function CitationChip({
    index,
    citation,
    onOpen,
}: {
    index: number;
    citation: Citation;
    onOpen: (passage: Passage) => void;
}) {
    const chip = useRef<HTMLSpanElement>(null);
    const [above, setAbove] = useState(false);
    const [open, setOpen] = useState(false);

    // The quote closes on a click, on Escape and on a press anywhere else, so it never floats on after the layout moves.
    useEffect(() => {
        if (!open) {
            return;
        }

        const close = (e: Event) => {
            if (
                e instanceof KeyboardEvent
                    ? e.key === 'Escape'
                    : !chip.current?.contains(e.target as Node)
            ) {
                setOpen(false);
            }
        };

        document.addEventListener('keydown', close);
        document.addEventListener('pointerdown', close);

        return () => {
            document.removeEventListener('keydown', close);
            document.removeEventListener('pointerdown', close);
        };
    }, [open]);

    // Open the quote upwards when the chip sits in the lower half of the window, so it is never cut off.
    const show = () => {
        setAbove(
            (chip.current?.getBoundingClientRect().top ?? 0) >
                window.innerHeight / 2,
        );
        setOpen(true);
    };

    return (
        <span
            ref={chip}
            onMouseEnter={show}
            onMouseLeave={() => setOpen(false)}
            onFocus={(e) => e.target.matches(':focus-visible') && show()}
            onBlur={() => setOpen(false)}
            className="group relative mx-0.5 inline-block align-baseline"
        >
            <button
                type="button"
                data-testid="citation"
                onClick={(e) => {
                    setOpen(false);
                    e.currentTarget.blur();
                    onOpen({
                        sourceId: citation.source_id,
                        start: citation.start,
                        end: citation.end,
                        page: citation.page,
                    });
                }}
                className="cursor-pointer rounded bg-primary/10 px-1 text-xs font-semibold text-primary hover:bg-primary/20"
            >
                {index + 1}
            </button>
            <span
                data-testid="citation-quote"
                className={`pointer-events-none absolute left-0 z-20 ${open ? 'block' : 'hidden'} ${above ? 'bottom-full mb-1' : 'top-full mt-1'} w-72 max-w-[75vw] rounded-md border bg-popover p-2 text-left text-xs font-normal text-popover-foreground shadow-md`}
            >
                <span className="block font-semibold">
                    {citation.source_title}
                </span>
                <span className="line-clamp-6 block">
                    &ldquo;{citation.quote}&rdquo;
                </span>
                <span className="block text-muted-foreground">
                    {citation.page ? `page ${citation.page}, ` : ''}characters{' '}
                    {citation.start}&ndash;{citation.end}. Click to read it in
                    the source.
                </span>
            </span>
        </span>
    );
}

/** Text with [P12] markers as numbered, hoverable citations. Markers the server dropped vanish. */
export function CitedText({
    text,
    citations,
    onOpen,
}: {
    text: string;
    citations: Citation[] | null;
    onOpen: (passage: Passage) => void;
}) {
    const cite = (passageId: number, key: string): ReactNode => {
        const index = citations?.findIndex((c) => c.id === passageId) ?? -1;

        return index < 0 ? null : (
            <CitationChip
                key={key}
                index={index}
                citation={citations![index]}
                onOpen={onOpen}
            />
        );
    };

    return <Markdown text={text} cite={cite} />;
}
