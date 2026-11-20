import { Agent, SlideFrame } from '@/slides/slide-frame';
import type { SlideContext } from '@/slides/slide-frame';
import type { SlideOf } from '@/slides/types';

/** A heading and three to five points, each led by an agent square; the first in signal blue. */
export function BulletsSlide({
    slide,
    context,
}: {
    slide: SlideOf<'bullets'>;
    context: SlideContext;
}) {
    const { heading, items } = slide.fields;

    return (
        <SlideFrame
            template="bullets"
            context={context}
            citations={slide.citations}
        >
            <h2 className="sl-heading">{heading}</h2>
            <ul className="sl-bullets">
                {items.map((item, i) => (
                    <li key={i}>
                        <Agent signal={i === 0} />
                        <span>{item}</span>
                    </li>
                ))}
            </ul>
        </SlideFrame>
    );
}
