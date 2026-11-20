import { Agent, SlideFrame } from '@/slides/slide-frame';
import type { SlideContext } from '@/slides/slide-frame';
import type { SlideOf } from '@/slides/types';

/** The number's size steps down with its length, so twelve characters still fit their column, and a longer one wraps small. */
export function numberSize(value: string): 'l' | 'm' | 's' | 'xs' {
    if (value.length <= 5) {
        return 'l';
    }

    if (value.length <= 8) {
        return 'm';
    }

    return value.length <= 12 ? 's' : 'xs';
}

/** One figure, large, its label, and a line of context beside it. */
export function BigNumberSlide({
    slide,
    context,
}: {
    slide: SlideOf<'big_number'>;
    context: SlideContext;
}) {
    const { heading, number, label, context: note } = slide.fields;

    return (
        <SlideFrame
            template="big_number"
            context={context}
            citations={slide.citations}
        >
            <h2 className="sl-heading">{heading}</h2>
            <div className="sl-big" data-fit-box>
                <figure>
                    <p className="sl-number" data-size={numberSize(number)}>
                        {number}
                    </p>
                    <figcaption className="sl-number-label">
                        <Agent signal /> {label}
                    </figcaption>
                </figure>
                <p className="sl-context">{note}</p>
            </div>
        </SlideFrame>
    );
}
