import { Constellation } from '@/slides/constellation';
import { Agent, SlideFrame } from '@/slides/slide-frame';
import type { SlideContext } from '@/slides/slide-frame';
import type { SlideOf } from '@/slides/types';

/** A chapter break: inverted ground, a signal kicker, the constellation across the right. */
export function SectionSlide({
    slide,
    context,
}: {
    slide: SlideOf<'section'>;
    context: SlideContext;
}) {
    const { heading, kicker } = slide.fields;

    return (
        <SlideFrame
            template="section"
            context={context}
            citations={slide.citations}
            inverted
            decoration={<Constellation variant="field" />}
        >
            <p className="sl-kicker">
                <Agent signal /> {kicker}
            </p>
            <h2>{heading}</h2>
        </SlideFrame>
    );
}
