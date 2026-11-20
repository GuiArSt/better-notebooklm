import { Agent, SlideFrame } from '@/slides/slide-frame';
import type { SlideContext } from '@/slides/slide-frame';
import type { Column, SlideOf } from '@/slides/types';

function ColumnCard({
    column,
    side,
}: {
    column: Column;
    side: 'left' | 'right';
}) {
    return (
        <div className={`sl-col is-${side}`}>
            <h3>
                <Agent signal={side === 'right'} /> {column.label}
            </h3>
            <ul>
                {column.items.map((item, i) => (
                    <li key={i}>{item}</li>
                ))}
            </ul>
        </div>
    );
}

/** Two sides of a comparison, as two soft cards; the right one carries the signal. */
export function TwoColumnsSlide({
    slide,
    context,
}: {
    slide: SlideOf<'two_columns'>;
    context: SlideContext;
}) {
    const { heading, left, right } = slide.fields;

    return (
        <SlideFrame
            template="two_columns"
            context={context}
            citations={slide.citations}
        >
            <h2 className="sl-heading">{heading}</h2>
            <div className="sl-cols" data-fit-box>
                <ColumnCard column={left} side="left" />
                <ColumnCard column={right} side="right" />
            </div>
        </SlideFrame>
    );
}
