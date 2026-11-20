import { ChevronLeft, ChevronRight, Maximize2, Printer } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Button } from '@/components/ui/button';
import { citeFrom, deckProblems, toDeck } from '@/lib/slides-deck';
import { PptxButton, SlideView } from '@/slides';
import type { SlideContext } from '@/slides';
import { cn } from '@/lib/utils';
import type { Passage } from '@/types/app';
import type { StudioItem } from '@/types/studio';

/**
 * A slide deck: a strip of thumbnails, the current slide, arrow keys, full screen, and Print / PDF (one slide per page,
 * through the print rules in app.css). The slides are Glyph's templates; the server's JSON is the only input.
 */
export function SlideViewer({
    item,
    onOpenCitation,
}: {
    item: StudioItem;
    onOpenCitation: (passage: Passage) => void;
}) {
    const deck = toDeck(item);
    const problems = deckProblems(item);
    const total = deck.slides.length;
    const [index, setIndex] = useState(0);
    const stage = useRef<HTMLDivElement>(null);
    const cite = citeFrom(item.citations);

    const go = useCallback(
        (to: number) =>
            setIndex((i) =>
                Math.min(
                    total - 1,
                    Math.max(0, typeof to === 'number' ? to : i),
                ),
            ),
        [total],
    );

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (
                ['INPUT', 'TEXTAREA', 'SELECT'].includes(
                    (e.target as HTMLElement)?.tagName,
                )
            ) {
                return;
            }

            if (
                e.key === 'ArrowRight' ||
                e.key === 'ArrowDown' ||
                e.key === 'PageDown'
            ) {
                e.preventDefault();
                setIndex((i) => Math.min(total - 1, i + 1));
            } else if (
                e.key === 'ArrowLeft' ||
                e.key === 'ArrowUp' ||
                e.key === 'PageUp'
            ) {
                e.preventDefault();
                setIndex((i) => Math.max(0, i - 1));
            } else if (e.key === 'Home') {
                setIndex(0);
            } else if (e.key === 'End') {
                setIndex(total - 1);
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [total]);

    if (total === 0 || problems.length > 0) {
        return (
            <div className="space-y-2 text-body" data-testid="deck-problem">
                <p className="text-muted-foreground">
                    This deck cannot be shown: it does not fit the slide
                    templates.
                </p>
                <ul className="list-disc pl-5 text-caption text-muted-foreground">
                    {problems.slice(0, 4).map((p) => (
                        <li key={p}>{p}</li>
                    ))}
                </ul>
            </div>
        );
    }

    const context = (i: number): SlideContext => ({
        index: i,
        total,
        deckTitle: deck.title,
        cite,
        onOpenCitation,
    });

    return (
        <div className="w-full max-w-full min-w-0 space-y-3" data-testid="deck">
            <div className="flex min-w-0 flex-col gap-3 md:flex-row">
                {/* The strip: vertical beside the slide on a wide screen, a row under it on a phone. */}
                <div
                    className="order-2 flex max-w-full min-w-0 gap-2 overflow-auto md:order-1 md:max-h-[60vh] md:w-36 md:shrink-0 md:flex-col"
                    role="listbox"
                    aria-label="Slides"
                    data-testid="deck-strip"
                >
                    {deck.slides.map((slide, i) => (
                        <button
                            key={i}
                            type="button"
                            role="option"
                            aria-selected={i === index}
                            aria-label={`Go to slide ${i + 1}`}
                            data-testid="deck-thumb"
                            onClick={() => go(i)}
                            className={cn(
                                'w-28 shrink-0 overflow-hidden rounded-lg border-2 border-transparent md:w-full',
                                i === index && 'border-primary',
                            )}
                        >
                            <div className="pointer-events-none">
                                <SlideView
                                    slide={slide}
                                    context={{
                                        ...context(i),
                                        onOpenCitation: undefined,
                                    }}
                                />
                            </div>
                        </button>
                    ))}
                </div>

                <div className="order-1 min-w-0 flex-1 md:order-2">
                    <div
                        ref={stage}
                        className="deck-stage flex w-full min-w-0 items-center justify-center overflow-hidden rounded-2xl bg-card-soft"
                        data-testid="deck-stage"
                    >
                        <div className="deck-slide w-full min-w-0">
                            <SlideView
                                slide={deck.slides[index]}
                                context={context(index)}
                            />
                        </div>
                    </div>
                </div>
            </div>

            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-1">
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Previous slide"
                        disabled={index === 0}
                        onClick={() => go(index - 1)}
                        data-testid="deck-prev"
                    >
                        <ChevronLeft />
                    </Button>
                    <span
                        className="min-w-14 text-center text-body tabular-nums"
                        data-testid="deck-position"
                    >
                        {index + 1} / {total}
                    </span>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Next slide"
                        disabled={index === total - 1}
                        onClick={() => go(index + 1)}
                        data-testid="deck-next"
                    >
                        <ChevronRight />
                    </Button>
                </div>
                <div className="flex items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        className="rounded-full"
                        onClick={() =>
                            void stage.current?.requestFullscreen?.()
                        }
                        data-testid="deck-fullscreen"
                    >
                        <Maximize2 /> Full screen
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        className="rounded-full"
                        onClick={() => window.print()}
                        data-testid="deck-print"
                    >
                        <Printer /> Print / PDF
                    </Button>
                    <PptxButton deck={deck} cite={cite} />
                </div>
            </div>

            {/* Only for printing: every slide, one per page. Hidden on screen. */}
            {createPortal(
                <div
                    className="deck-print-root"
                    aria-hidden="true"
                    data-testid="deck-print-root"
                >
                    {deck.slides.map((slide, i) => (
                        <div key={i} className="deck-print-page">
                            <SlideView slide={slide} context={context(i)} />
                        </div>
                    ))}
                </div>,
                document.body,
            )}
        </div>
    );
}
