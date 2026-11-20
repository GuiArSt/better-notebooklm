import type { SlideContext } from '@/slides/slide-frame';
import { BigNumberSlide } from '@/slides/templates/big-number-slide';
import { BulletsSlide } from '@/slides/templates/bullets-slide';
import { QuoteSlide } from '@/slides/templates/quote-slide';
import { SectionSlide } from '@/slides/templates/section-slide';
import { StepsSlide } from '@/slides/templates/steps-slide';
import { TitleSlide } from '@/slides/templates/title-slide';
import { TwoColumnsSlide } from '@/slides/templates/two-columns-slide';
import type { Slide } from '@/slides/types';

/** Draws any slide of the contract with its template. */
export function SlideView({
    slide,
    context,
}: {
    slide: Slide;
    context: SlideContext;
}) {
    switch (slide.template) {
        case 'title':
            return <TitleSlide slide={slide} context={context} />;
        case 'section':
            return <SectionSlide slide={slide} context={context} />;
        case 'bullets':
            return <BulletsSlide slide={slide} context={context} />;
        case 'two_columns':
            return <TwoColumnsSlide slide={slide} context={context} />;
        case 'big_number':
            return <BigNumberSlide slide={slide} context={context} />;
        case 'quote':
            return <QuoteSlide slide={slide} context={context} />;
        case 'steps':
            return <StepsSlide slide={slide} context={context} />;
    }
}
