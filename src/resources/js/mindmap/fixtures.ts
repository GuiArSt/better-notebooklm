/** A mind map as the model writes it (StudioGenerator::parseMindmap), for the tests and the preview. */
import type { MindTree } from '@/mindmap/tree';
import type { Citation } from '@/types/app';

const leaf = (label: string, refs: number[] = []) => ({
    label,
    refs,
    children: [],
});

export const sampleTree: MindTree = {
    title: 'Voyager 1 and 2',
    children: [
        {
            label: 'The Grand Tour',
            refs: [1],
            children: [
                leaf('Jupiter', [1]),
                leaf('Saturn', [2]),
                {
                    label: 'Uranus and Neptune',
                    refs: [3],
                    children: [leaf('Only Voyager 2'), leaf('1986 and 1989')],
                },
            ],
        },
        {
            label: 'Interstellar space',
            refs: [4],
            children: [
                leaf('Heliopause in 2012', [4]),
                leaf('Still sending data'),
            ],
        },
        leaf('The golden record', [5]),
    ],
};

/** The passages the sample map cites, in chat's citation shape. */
export const sampleCitations: Citation[] = [1, 2, 3, 4, 5].map((id) => ({
    id,
    source_id: id <= 3 ? 1 : 2,
    source_title: id <= 3 ? 'Voyager 2 · Wikipedia' : 'Voyager 1 · Wikipedia',
    page: null,
    start: id * 100,
    end: id * 100 + 80,
    quote: 'A passage from the source.',
}));
