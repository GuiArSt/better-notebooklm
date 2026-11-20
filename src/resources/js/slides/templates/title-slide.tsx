import { Constellation } from '@/slides/constellation';
import { Agent, SlideFrame } from '@/slides/slide-frame';
import type { SlideContext } from '@/slides/slide-frame';
import type { SlideOf } from '@/slides/types';

/** The opening slide: the deck's name, large, under a constellation. */
export function TitleSlide({
    slide,
    context,
}: {
    slide: SlideOf<'title'>;
    context: SlideContext;
}) {
    const { heading, subheading } = slide.fields;

    return (
        <SlideFrame
            template="title"
            context={context}
            citations={slide.citations}
            decoration={<Constellation variant="corner" />}
        >
            <p className="sl-eyebrow">
                <Agent signal /> Slide deck
            </p>
            <h1>{heading}</h1>
            <p className="sl-sub">{subheading}</p>
        </SlideFrame>
    );
}
