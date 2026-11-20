import { buildPptx, pptxFileName } from '@/slides/pptx/deck-to-pptx';
import type { PptxOptions } from '@/slides/pptx/deck-to-pptx';
import type { Deck } from '@/slides/types';

/** Builds the deck's .pptx and saves it. pptxgenjs is loaded here, on demand, so it stays off the main bundle. Errors are thrown. */
export async function downloadPptx(
    deck: Deck,
    opts: PptxOptions = {},
): Promise<void> {
    const { default: PptxGenJS } = await import('pptxgenjs');
    const pres = buildPptx(deck, PptxGenJS, opts);
    await pres.writeFile({ fileName: pptxFileName(deck) });
}
