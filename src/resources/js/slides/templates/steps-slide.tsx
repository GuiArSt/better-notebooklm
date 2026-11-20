import { SlideFrame } from '@/slides/slide-frame';
import type { SlideContext } from '@/slides/slide-frame';
import type { SlideOf } from '@/slides/types';

/** Three to five steps in a row, numbered in agent squares joined by a hairline; the last one in signal blue. */
export function StepsSlide({
    slide,
    context,
}: {
    slide: SlideOf<'steps'>;
    context: SlideContext;
}) {
    const { heading, steps } = slide.fields;

    return (
        <SlideFrame
            template="steps"
            context={context}
            citations={slide.citations}
        >
            <h2 className="sl-heading">{heading}</h2>
            <ol className="sl-steps" data-count={steps.length} data-fit-box>
                {steps.map((step, i) => (
                    <li key={i}>
                        <span className="sl-step-n">{i + 1}</span>
                        <p className="sl-step-label">{step.label}</p>
                        <p className="sl-step-text">{step.text}</p>
                    </li>
                ))}
            </ol>
        </SlideFrame>
    );
}
