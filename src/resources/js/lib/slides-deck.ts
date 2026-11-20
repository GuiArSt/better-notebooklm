import { checkDeck } from '@/slides';
import type { Deck, Slide } from '@/slides';
import type { Citation } from '@/types/app';
import type { StudioItem } from '@/types/studio';

/** The server stores a slide's citations as passage ids (12); the templates take "P12". */
export function toDeck(item: Pick<StudioItem, 'title' | 'slides'>): Deck {
    const raw = Array.isArray(item.slides)
        ? (item.slides as {
              template: string;
              fields: unknown;
              citations?: (number | string)[];
          }[])
        : [];

    return {
        title: item.title,
        slides: raw.map((s) => ({
            template: s.template,
            fields: s.fields,
            citations: (s.citations ?? []).map((id) =>
                typeof id === 'number' ? `P${id}` : String(id),
            ),
        })) as Slide[],
    };
}

/** Resolves "P12" to the citation the server validated; anything it did not validate gets no chip. */
export function citeFrom(
    citations: Citation[] | null,
): (id: string) => Citation | undefined {
    return (id) => citations?.find((c) => `P${c.id}` === id);
}

/** Problems with a deck as the client sees it (the server is the real gate); empty when it is fine to show. */
export function deckProblems(
    item: Pick<StudioItem, 'title' | 'slides'>,
): string[] {
    return checkDeck(toDeck(item));
}
