import { Agent, SlideFrame } from '@/slides/slide-frame';
import type { SlideContext } from '@/slides/slide-frame';
import type { SlideOf } from '@/slides/types';

/** A quotation from the sources, set large, with its attribution. */
export function QuoteSlide({
    slide,
    context,
}: {
    slide: SlideOf<'quote'>;
    context: SlideContext;
}) {
    const { quote, attribution } = slide.fields;

    return (
        <SlideFrame
            template="quote"
            context={context}
            citations={slide.citations}
        >
            <svg
                className="sl-quote-mark"
                viewBox="0 0 51 36"
                aria-hidden="true"
            >
                <path
                    fill="currentColor"
                    d="M0 36V22.5C0 9.9 6.3 2.4 18.9 0l2.4 4.8C14.4 6.6 11.1 10.5 10.8 16.2H19.2V36H0Zm28.8 0V22.5C28.8 9.9 35.1 2.4 47.7 0l2.4 4.8c-6.9 1.8-10.2 5.7-10.5 11.4H48V36H28.8Z"
                />
            </svg>
            <blockquote>{quote}</blockquote>
            <p className="sl-attribution">
                <Agent signal /> {attribution}
            </p>
        </SlideFrame>
    );
}
