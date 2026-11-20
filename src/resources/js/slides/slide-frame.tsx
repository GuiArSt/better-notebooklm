import { useRef } from 'react';
import type { ReactNode } from 'react';
import { CitationChip } from '@/components/notebook/citation';
import { useFitText } from '@/slides/fit/use-fit-text';
import type { TemplateName } from '@/slides/types';
import type { Citation, Passage } from '@/types/app';
import './slides.css';

/** Where a slide sits in its deck, and how to show its citations. */
export type SlideContext = {
    /** 0-based position in the deck. */
    index: number;
    total: number;
    deckTitle: string;
    /** Resolves a passage id ("P12") to its citation; ids it cannot resolve are not drawn. */
    cite?: (id: string) => Citation | undefined;
    onOpenCitation?: (passage: Passage) => void;
};

const noop = () => undefined;

/** How far each template may shrink its text (never below 0.45). Lower one only when a browser shows 2.5x text still overflowing at its floor. */
const FLOOR: Record<TemplateName, number> = {
    title: 0.6,
    section: 0.6,
    bullets: 0.6,
    two_columns: 0.6,
    big_number: 0.6,
    quote: 0.6,
    steps: 0.6,
};

/**
 * The 16:9 canvas every template draws on. It fills its container's width and scales everything inside with it
 * (container-query units in slides.css), so the same slide works in a thumbnail, the viewer, a phone and print.
 */
export function SlideFrame({
    template,
    context,
    citations,
    inverted = false,
    decoration,
    children,
}: {
    template: TemplateName;
    context: SlideContext;
    citations: string[];
    inverted?: boolean;
    decoration?: ReactNode;
    children: ReactNode;
}) {
    const { index, total, deckTitle, cite, onOpenCitation } = context;
    const body = useRef<HTMLDivElement>(null);
    useFitText(body, { min: FLOOR[template] });
    const resolved = cite
        ? citations
              .map((id) => cite(id))
              .filter((c): c is Citation => c !== undefined)
        : [];

    return (
        <div className="sl-frame">
            <section
                className={`sl sl--${template}${inverted ? ' is-inverted' : ''}`}
                data-template={template}
                data-testid="slide"
                aria-label={`Slide ${index + 1} of ${total}`}
            >
                {decoration}
                <div className="sl-body" ref={body}>
                    {children}
                </div>
                <footer className="sl-footer">
                    <span className="sl-footer-title">
                        {index === 0 ? '' : deckTitle}
                    </span>
                    {resolved.length > 0 && (
                        <span
                            className="sl-cites"
                            data-testid="slide-citations"
                        >
                            {resolved.map((c, k) => (
                                <CitationChip
                                    key={c.id}
                                    index={k}
                                    citation={c}
                                    onOpen={onOpenCitation ?? noop}
                                />
                            ))}
                        </span>
                    )}
                    <span className="sl-page">
                        {index + 1} / {total}
                    </span>
                </footer>
            </section>
        </div>
    );
}

/** The mark's agent: a small rounded square. */
export function Agent({ signal = false }: { signal?: boolean }) {
    return (
        <span
            aria-hidden="true"
            className={`sl-agent${signal ? ' is-signal' : ''}`}
        />
    );
}
